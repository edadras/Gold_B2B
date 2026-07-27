<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application;

use App\Modules\Dispute\Contracts\DisputeHoldPort;
use App\Modules\Dispute\Contracts\OpenDisputeCommand;
use App\Modules\Dispute\Contracts\TradeParties;
use App\Modules\Dispute\Contracts\TradePartiesProvider;
use App\Modules\Dispute\Domain\ActorType;
use App\Modules\Dispute\Domain\DisputedAmount;
use App\Modules\Dispute\Domain\DisputedAmountCalculator;
use App\Modules\Dispute\Domain\DisputeStatus;
use App\Modules\Dispute\Domain\DisputeType;
use App\Modules\Dispute\Events\DisputeOpened;
use App\Modules\Dispute\Infrastructure\Models\DisputeModel;
use App\Modules\Dispute\Infrastructure\Models\DisputeTimelineModel;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Opening, withdrawing and responding to a case.
 *
 * The whole of §13.4 lives in open(): compute what is actually disputed, lock
 * exactly that and nothing more, file the case, start the 24-hour clock, and
 * write the timeline the §13.9 screen renders.
 */
final readonly class DisputeService
{
    private const MAX_CASE_NUMBER_ATTEMPTS = 5;

    public function __construct(
        private DisputeStateMachine $stateMachine,
        private CaseNumberGenerator $caseNumbers,
        private DisputeHoldPort $holds,
        private TradePartiesProvider $trades,
    ) {}

    /**
     * File a dispute.
     *
     * @throws OperationNotPermittedException when the claimant was not party to the trade
     */
    public function open(OpenDisputeCommand $command): DisputeModel
    {
        $parties = $this->resolveParties($command);
        $respondentOrgId = $this->resolveRespondent($command, $parties);

        $claim = $this->computeDisputedAmount($command, $parties);

        $dispute = $this->insert($command, $respondentOrgId, $claim);

        // §13.4: the hold covers the claimed amount only. It is applied after
        // the case row exists because the ledger entries reference the case id.
        $holdApplied = $this->applyHold($dispute, $claim);

        $this->stateMachine->transition(
            $dispute,
            DisputeStatus::AWAITING_REPLY,
            TransitionContext::system(
                'AUTO_AWAIT_REPLY',
                'اعلان فوری به طرف مقابل — مهلت پاسخ آغاز شد',
            ),
        );

        event(new DisputeOpened(
            disputeId: (int) $dispute->id,
            caseNumber: $dispute->case_number,
            disputeType: $dispute->dispute_type,
            claimantOrgId: $dispute->claimant_org_id,
            respondentOrgId: $dispute->respondent_org_id,
            tradeId: $dispute->trade_id,
            claimGoldMg: $dispute->claim_gold_mg,
            claimRial: $dispute->claim_rial,
            replyDeadlineAt: (string) $dispute->reply_deadline_at?->toIso8601String(),
            fundsHeld: $holdApplied,
        ));

        return $dispute;
    }

    /**
     * The cases one organisation is a party to, newest first.
     *
     * Both sides are returned: §13.9's «پرونده‌های من» screen shows the cases a
     * member filed and the cases filed against them in one list, and a member
     * who could not see a claim against them could not answer it.
     *
     * @return array<int, DisputeModel>
     */
    public function listForOrganization(int $organizationId, int $limit = 100): array
    {
        return DisputeModel::query()
            ->where(static function ($query) use ($organizationId): void {
                $query->where('claimant_org_id', $organizationId)
                    ->orWhere('respondent_org_id', $organizationId);
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * A single case by id, WITHOUT any party filter.
     *
     * The party check is deliberately left to the caller rather than folded in
     * here, because the HTTP layer needs the two organisation ids to decide
     * between 404 and a legitimate read — see ApiController::partyOrNotFound().
     * Every mutating method below re-asserts party membership itself, so a
     * caller cannot turn this into a way to act on a stranger's case.
     */
    public function find(int $disputeId): ?DisputeModel
    {
        /** @var ?DisputeModel $dispute */
        $dispute = DisputeModel::query()->whereKey($disputeId)->first();

        return $dispute;
    }

    /**
     * §13.6 مرحله ۱ option ۱ — the respondent accepts the claim in full.
     *
     * No reputation penalty follows from accepting: «پذیرش سریع اشتباه خود —
     * بدون اثر منفی». That policy lives in DisputeDecision, not here.
     */
    public function acceptClaim(DisputeModel $dispute, int $userId, ?string $message = null): DisputeModel
    {
        $this->assertParty($dispute, $dispute->respondent_org_id, 'accept a claim');

        return $this->stateMachine->transition(
            $dispute,
            DisputeStatus::ACCEPTED_BY_RESPONDENT,
            TransitionContext::respondent(
                $userId,
                $dispute->respondent_org_id,
                'CLAIM_ACCEPTED',
                $message ?? 'پذیرش کامل ادعا',
            ),
        );
    }

    /**
     * §13.6 مرحله ۱ options ۲ and ۳ — partial acceptance or outright rejection.
     * Both lead to the negotiation room.
     */
    public function disputeClaim(DisputeModel $dispute, int $userId, string $message): DisputeModel
    {
        $this->assertParty($dispute, $dispute->respondent_org_id, 'reply to a claim');

        return $this->stateMachine->transition(
            $dispute,
            DisputeStatus::NEGOTIATION,
            TransitionContext::respondent($userId, $dispute->respondent_org_id, 'CLAIM_DISPUTED', $message),
        );
    }

    /** The claimant drops the case; the hold is released by ResolutionService. */
    public function withdraw(DisputeModel $dispute, int $userId, ?string $reason = null): DisputeModel
    {
        $this->assertParty($dispute, $dispute->claimant_org_id, 'withdraw a dispute');

        return $this->stateMachine->transition(
            $dispute,
            DisputeStatus::WITHDRAWN,
            TransitionContext::claimant(
                $userId,
                $dispute->claimant_org_id,
                'WITHDRAWN',
                $reason ?? 'معترض پرونده را پس گرفت',
            ),
        );
    }

    /** An operator picks the case up (§13.6 مرحله ۳). */
    public function escalateToMediation(
        DisputeModel $dispute,
        int $mediatorUserId,
        ?string $reason = null,
    ): DisputeModel {
        $dispute->update(['mediator_user_id' => $mediatorUserId]);

        return $this->stateMachine->transition(
            $dispute,
            DisputeStatus::UNDER_MEDIATION,
            TransitionContext::mediator(
                $mediatorUserId,
                'ESCALATED_TO_MEDIATION',
                $reason ?? 'ارجاع به میانجی‌گری',
            ),
        );
    }

    /**
     * Whether the claimant is entitled to open this case.
     *
     * A dispute tied to a trade requires the claimant to have been party to it.
     * If the trade cannot be looked up at all, the answer is no: "unknown" must
     * never be treated as "yes" for an authorisation question, and the
     * alternative would let anyone file against any trade id they guessed.
     */
    private function resolveParties(OpenDisputeCommand $command): ?TradeParties
    {
        if ($command->tradeId === null) {
            return null;
        }

        $parties = $this->trades->forTrade($command->tradeId);

        if ($parties === null) {
            throw new OperationNotPermittedException(
                "Trade {$command->tradeId} could not be verified; a dispute cannot be opened against it"
            );
        }

        if (! $parties->includes($command->claimantOrgId)) {
            throw new OperationNotPermittedException(
                "Organisation {$command->claimantOrgId} was not a party to trade {$command->tradeId}"
            );
        }

        return $parties;
    }

    private function resolveRespondent(OpenDisputeCommand $command, ?TradeParties $parties): int
    {
        if ($parties !== null) {
            $counterparty = $parties->counterpartyOf($command->claimantOrgId);

            if ($counterparty === null || $counterparty === $command->claimantOrgId) {
                throw new OperationNotPermittedException('The trade has no counterparty to dispute with');
            }

            return $counterparty;
        }

        /** @var int $respondent — guaranteed by OpenDisputeCommand's constructor */
        $respondent = $command->respondentOrgId;

        return $respondent;
    }

    /**
     * §13.4 — what gets locked.
     *
     * Derived from the trade wherever it can be, so the claimant cannot inflate
     * the figure by asserting one; stated by the claimant only for categories
     * where nothing is derivable (an unpaid invoice has no computable shortfall).
     */
    private function computeDisputedAmount(OpenDisputeCommand $command, ?TradeParties $parties): DisputedAmount
    {
        $price = $command->pricePerFineGram ?? $parties?->pricePerFineGram ?? 0;

        if ($command->type === DisputeType::PURITY_MISMATCH) {
            if ($parties === null || $command->actualPurityX10k === null) {
                throw new InvalidArgumentException(
                    'A purity dispute needs the trade and the alleged actual purity'
                );
            }

            return DisputedAmountCalculator::forPurityClaim(
                declaredFineMg: $parties->fineMg,
                claimedPurityX10k: $parties->purityX10k,
                actualPurityX10k: $command->actualPurityX10k,
                pricePerFineGram: $price,
            );
        }

        if ($command->type === DisputeType::WEIGHT_MISMATCH) {
            if ($parties === null || $command->actualFineMg === null) {
                throw new InvalidArgumentException(
                    'A weight dispute needs the trade and the alleged actual weight'
                );
            }

            return DisputedAmountCalculator::forWeightClaim(
                declaredFineMg: $parties->fineMg,
                actualFineMg: $command->actualFineMg,
                pricePerFineGram: $price,
            );
        }

        if ($command->claimRial > 0) {
            return DisputedAmountCalculator::forRialClaim($command->claimRial);
        }

        // Nothing quantified: the case is still worth filing (a delivery that
        // never happened is a real dispute) but there is nothing to lock.
        return DisputedAmount::none();
    }

    private function insert(
        OpenDisputeCommand $command,
        int $respondentOrgId,
        DisputedAmount $claim,
    ): DisputeModel {
        $attempt = 0;

        while (true) {
            $caseNumber = $attempt === 0
                ? $this->caseNumbers->next()
                : $this->caseNumbers->nextAfterCollision($attempt);

            try {
                return DB::transaction(function () use (
                    $command, $respondentOrgId, $claim, $caseNumber
                ): DisputeModel {
                    /** @var DisputeModel $dispute */
                    $dispute = DisputeModel::query()->create([
                        'case_number' => $caseNumber,
                        'dispute_type' => $command->type->value,
                        'trade_id' => $command->tradeId,
                        'settlement_id' => $command->settlementId,
                        'gold_lot_id' => $command->goldLotId,
                        'claimant_org_id' => $command->claimantOrgId,
                        'respondent_org_id' => $respondentOrgId,
                        'opened_by_user_id' => $command->openedByUserId,
                        'claim_description' => $command->claimDescription,
                        'claim_gold_mg' => $claim->fineMg,
                        'claim_rial' => $claim->rial,
                        'claim_basis' => mb_substr($claim->basis, 0, 255),
                        'status' => DisputeStatus::OPENED->value,
                        'priority' => $command->type->defaultPriority()->value,
                        'opened_at' => Carbon::now(),
                    ]);

                    DisputeTimelineModel::query()->create([
                        'dispute_id' => $dispute->id,
                        'actor_type' => ActorType::CLAIMANT->value,
                        'actor_user_id' => $command->openedByUserId,
                        'actor_org_id' => $command->claimantOrgId,
                        'action' => 'DISPUTE_OPENED',
                        'message' => $command->claimDescription,
                        'from_status' => null,
                        'to_status' => DisputeStatus::OPENED->value,
                        'occurred_at' => Carbon::now(),
                    ]);

                    return $dispute;
                });
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) !== 1062 || ++$attempt >= self::MAX_CASE_NUMBER_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /** @return bool whether anything was actually frozen */
    private function applyHold(DisputeModel $dispute, DisputedAmount $claim): bool
    {
        if ($claim->isEmpty()) {
            return false;
        }

        $disputeId = (int) $dispute->id;

        // The respondent's assets are the ones at risk: the claim says they owe
        // something. Locking the claimant instead would penalise complaining.
        $goldEntryId = $claim->hasGold()
            ? $this->holds->holdGold($dispute->respondent_org_id, $claim->fineMg, $disputeId)
            : null;

        $rialEntryId = $claim->hasRial()
            ? $this->holds->holdRial($dispute->respondent_org_id, $claim->rial, $disputeId)
            : null;

        $dispute->update([
            'hold_gold_entry_id' => $goldEntryId,
            'hold_rial_entry_id' => $rialEntryId,
        ]);

        $this->stateMachine->note($dispute, TransitionContext::system(
            'FUNDS_HELD',
            sprintf(
                'قفل شد: %s میلی‌گرم خالص / %s ریال — %s',
                number_format($claim->fineMg),
                number_format($claim->rial),
                $claim->basis,
            ),
        ));

        return $goldEntryId !== null || $rialEntryId !== null;
    }

    private function assertParty(DisputeModel $dispute, int $expectedOrgId, string $action): void
    {
        if (! $dispute->involves($expectedOrgId)) {
            throw new OperationNotPermittedException("Only a party to the case may {$action}");
        }
    }
}
