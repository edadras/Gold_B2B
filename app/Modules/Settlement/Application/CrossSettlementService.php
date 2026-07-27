<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Application;

use App\Modules\Ledger\Contracts\GroupWriter;
use App\Modules\Ledger\Contracts\LedgerInterface;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Settlement\Domain\CrossSettlementStatus;
use App\Modules\Settlement\Domain\CrossSettlementTerms;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Domain\TransitionContext;
use App\Modules\Settlement\Events\CrossSettlementExecuted;
use App\Modules\Settlement\Infrastructure\Models\CrossSettlementModel;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use App\Modules\Shared\Support\IntMath;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use Illuminate\Support\Facades\DB;

/**
 * Cross settlement — docs/03-domain/05-settlement.md §5.7.
 *
 * «بدهی ریالی با طلا تسویه شود یا برعکس.» Two members hold obligations of
 * different asset classes that point in opposite directions; instead of A
 * finding five billion rial and B finding a hundred grams, A's debt is
 * discharged out of the metal B already owes it.
 *
 * §5.7 attaches four conditions to that, and this class is built around them:
 *
 *  1. **Explicit agreement from both sides.** propose() records the proposer's
 *     consent; agree() records the other's; execute() refuses anything that is
 *     not AGREED and re-reads the two timestamps from the row rather than
 *     trusting the status. Silence is not consent — the same rule ADR-009
 *     imposes on netting, for the same reason.
 *
 *  2. **A rate both accept, recorded transparently.** The rate is written on
 *     the cross_settlements row when the deal is proposed and is what execute()
 *     converts with. This service is not given a price reader and could not
 *     consult the market if it wanted to: reading a live price at execution
 *     time would mean the timing of a click changed how much metal a member
 *     owes, and the two parties would have agreed to different numbers.
 *
 *  3. **Integer arithmetic, floors, and the dust accounted for.** All of the
 *     conversion lives in CrossSettlementTerms. Flooring the metal leaves a
 *     little of the rial debt uncovered — 21,000 rial on §5.7's own example —
 *     and that residue is posted to SYSTEM/ROUNDING_DIFFERENCE rather than
 *     dropped, so the ledger still sums to zero per asset (invariant I4).
 *
 *  4. **A compliance note.** Carried on the row from proposal onward; §5.7 asks
 *     for «تأیید انطباق شرعی/حقوقی» and this module's job is to make sure the
 *     record exists, not to adjudicate it.
 *
 * What actually moves: nothing between the members. Crossing is netting across
 * asset classes, so the debtor's locked rial returns to it (the debt was paid
 * in metal, not cash) and the creditor keeps the metal it no longer has to
 * deliver (that metal IS the payment). Both settlements' locked figures shrink
 * by exactly what was crossed, and whatever is left stays outstanding — §5.7's
 * «باقیمانده: A بستانکار 36.306g».
 */
final class CrossSettlementService
{
    /** Only an obligation still awaiting payment can be crossed. */
    private const CROSSABLE_RIAL_STATUSES = [
        SettlementStatus::PAYMENT_PENDING,
        SettlementStatus::PAYMENT_DECLARED,
        SettlementStatus::OVERDUE,
    ];

    /** The metal side only has to still be holding metal. */
    private const CROSSABLE_GOLD_STATUSES = [
        SettlementStatus::ASSETS_LOCKED,
        SettlementStatus::PAYMENT_PENDING,
        SettlementStatus::PAYMENT_DECLARED,
        SettlementStatus::PAYMENT_CONFIRMED,
        SettlementStatus::OVERDUE,
    ];

    public function __construct(
        private readonly SettlementStateMachine $stateMachine,
        private readonly LedgerInterface $ledger,
    ) {}

    // ── propose ──────────────────────────────────────────────────────────────

    /**
     * Offer to discharge $rialSettlementId's cash obligation with the metal
     * locked on $goldSettlementId, at $agreedRate.
     *
     * Nothing moves. The row is created in PROPOSED with the proposer's own
     * consent already recorded, because proposing a deal is agreeing to it.
     */
    public function propose(
        int $rialSettlementId,
        int $goldSettlementId,
        PricePerFineGram $agreedRate,
        int $proposedByOrgId,
        ?int $proposedByUserId = null,
        ?string $complianceNote = null,
    ): CrossSettlementModel {
        return DB::transaction(function () use (
            $rialSettlementId,
            $goldSettlementId,
            $agreedRate,
            $proposedByOrgId,
            $proposedByUserId,
            $complianceNote,
        ): CrossSettlementModel {
            [$rial, $gold] = $this->lockPair($rialSettlementId, $goldSettlementId);

            $this->assertProposable($rial, $gold, $agreedRate, $proposedByOrgId);

            $debtor = $rial->cash_payer_org_id;
            $creditor = $rial->cash_receiver_org_id;
            $now = now();

            $cross = CrossSettlementModel::query()->create([
                'cross_code' => 'CRS-PENDING-'.bin2hex(random_bytes(4)),
                'rial_settlement_id' => (int) $rial->id,
                'gold_settlement_id' => (int) $gold->id,
                'debtor_org_id' => $debtor,
                'creditor_org_id' => $creditor,
                'agreed_rate_rial' => $agreedRate->rial,
                'rial_obligation_rial' => $rial->locked_cash_rial,
                'gold_obligation_mg' => $gold->locked_gold_mg,
                'status' => CrossSettlementStatus::PROPOSED->value,
                'proposed_by_org_id' => $proposedByOrgId,
                'proposed_by_user_id' => $proposedByUserId,
                'compliance_note' => $complianceNote,
                'debtor_agreed_at' => $proposedByOrgId === $debtor ? $now : null,
                'debtor_agreed_by_user_id' => $proposedByOrgId === $debtor ? $proposedByUserId : null,
                'creditor_agreed_at' => $proposedByOrgId === $creditor ? $now : null,
                'creditor_agreed_by_user_id' => $proposedByOrgId === $creditor ? $proposedByUserId : null,
            ]);

            $cross->cross_code = self::codeFor((int) $cross->id);
            $cross->save();

            return $cross->refresh();
        }, attempts: 3);
    }

    // ── agree / reject ───────────────────────────────────────────────────────

    /**
     * The other side says yes. Idempotent for a party that already agreed.
     *
     * Once both timestamps are present the row moves to AGREED, which is the
     * only state execute() will act on.
     */
    public function agree(int $crossId, int $organizationId, ?int $userId = null): CrossSettlementModel
    {
        return DB::transaction(function () use ($crossId, $organizationId, $userId): CrossSettlementModel {
            $cross = $this->lockCross($crossId);

            if (! $cross->status->isOpen()) {
                throw new OperationNotPermittedException(
                    'Cross settlement '.$cross->cross_code.' is '.$cross->status->value
                        .' and no longer accepts agreement'
                );
            }

            if (! $cross->involves($organizationId)) {
                throw new OperationNotPermittedException(
                    'Organisation '.$organizationId.' is not a party to '.$cross->cross_code
                );
            }

            $now = now();

            if ($organizationId === $cross->debtor_org_id && $cross->debtor_agreed_at === null) {
                $cross->debtor_agreed_at = $now;
                $cross->debtor_agreed_by_user_id = $userId;
            }

            if ($organizationId === $cross->creditor_org_id && $cross->creditor_agreed_at === null) {
                $cross->creditor_agreed_at = $now;
                $cross->creditor_agreed_by_user_id = $userId;
            }

            if ($cross->bothPartiesAgreed()) {
                $cross->status = CrossSettlementStatus::AGREED;
            }

            $cross->save();

            return $cross->refresh();
        }, attempts: 3);
    }

    /** Either party may kill the deal until it has executed. */
    public function reject(int $crossId, int $organizationId, ?string $reason = null): CrossSettlementModel
    {
        return DB::transaction(function () use ($crossId, $organizationId, $reason): CrossSettlementModel {
            $cross = $this->lockCross($crossId);

            if (! $cross->status->canTransitionTo(CrossSettlementStatus::REJECTED)) {
                throw new OperationNotPermittedException(
                    'Cross settlement '.$cross->cross_code.' is '.$cross->status->value.' and cannot be rejected'
                );
            }

            if (! $cross->involves($organizationId)) {
                throw new OperationNotPermittedException(
                    'Organisation '.$organizationId.' is not a party to '.$cross->cross_code
                );
            }

            $cross->status = CrossSettlementStatus::REJECTED;
            $cross->rejected_reason = $reason;
            $cross->rejected_at = now();
            $cross->save();

            return $cross->refresh();
        }, attempts: 3);
    }

    // ── execute ──────────────────────────────────────────────────────────────

    /**
     * Perform the cross at the rate recorded on the row.
     *
     * One transaction, one ledger group, both settlements adjusted. The event
     * goes out after commit (AGENT_BRIEF rule 3).
     */
    public function execute(int $crossId, ?int $actorUserId = null): CrossSettlementModel
    {
        /** @var CrossSettlementModel $cross */
        $cross = DB::transaction(
            fn (): CrossSettlementModel => $this->executeWithinTransaction($crossId, $actorUserId),
            attempts: 3,
        );

        event(new CrossSettlementExecuted(
            crossSettlementId: (int) $cross->id,
            crossCode: (string) $cross->cross_code,
            rialSettlementId: $cross->rial_settlement_id,
            goldSettlementId: $cross->gold_settlement_id,
            debtorOrgId: $cross->debtor_org_id,
            creditorOrgId: $cross->creditor_org_id,
            agreedRateRial: $cross->agreed_rate_rial,
            rialObligationRial: $cross->rial_obligation_rial,
            goldObligationMg: $cross->gold_obligation_mg,
            goldAppliedMg: $cross->gold_applied_mg,
            rialDischargedRial: $cross->rial_discharged_rial,
            goldValueRial: $cross->gold_value_rial,
            roundingRial: $cross->rounding_rial,
            goldRemainingMg: $cross->gold_remaining_mg,
            rialRemainingRial: $cross->rial_remaining_rial,
            transactionGroup: $cross->transaction_group === null ? null : (string) $cross->transaction_group,
            occurredAt: now()->toIso8601String(),
        ));

        return $cross;
    }

    private function executeWithinTransaction(int $crossId, ?int $actorUserId): CrossSettlementModel
    {
        $cross = $this->lockCross($crossId);

        // Re-read the agreements from the row instead of trusting the status:
        // a status is a cache of two facts, and it is the facts §5.7 requires.
        if ($cross->status !== CrossSettlementStatus::AGREED || ! $cross->bothPartiesAgreed()) {
            throw new OperationNotPermittedException(
                'Cross settlement '.$cross->cross_code
                    .' needs the explicit agreement of both parties before it can be executed'
            );
        }

        [$rial, $gold] = $this->lockPair($cross->rial_settlement_id, $cross->gold_settlement_id);

        $this->assertStillCrossable($rial, $gold);

        // The obligations as they stand now, capped by what was agreed: a
        // partial payment in the meantime shrinks the deal, it does not
        // enlarge it beyond what the two parties looked at.
        $rialObligation = min($rial->locked_cash_rial, $cross->rial_obligation_rial);
        $goldObligation = min($gold->locked_gold_mg, $cross->gold_obligation_mg);

        $terms = CrossSettlementTerms::compute($rialObligation, $goldObligation, $cross->agreedRate());

        if (! $terms->crossesAnything()) {
            throw new OperationNotPermittedException(
                'Cross settlement '.$cross->cross_code.' would discharge nothing at the agreed rate'
            );
        }

        $group = $this->postCross($cross, $rial, $gold, $terms);

        $this->applyToSettlements($cross, $rial, $gold, $terms, $group, $actorUserId);

        $cross->gold_applied_mg = $terms->goldAppliedMg;
        $cross->rial_discharged_rial = $terms->rialDischargedRial;
        $cross->gold_value_rial = $terms->goldValueRial;
        $cross->rounding_rial = $terms->roundingRial;
        $cross->gold_remaining_mg = $terms->goldRemainingMg;
        $cross->rial_remaining_rial = $terms->rialRemainingRial;
        $cross->status = CrossSettlementStatus::EXECUTED;
        $cross->executed_at = now();
        $cross->transaction_group = $group;
        $cross->save();

        return $cross->refresh();
    }

    /**
     * The whole cross as one balanced transaction_group.
     *
     * Per asset:
     *
     *   RIAL   debtor   held bucket  −discharged   the debt is settled, not paid
     *          debtor   AVAILABLE    +discharged   so the locked cash comes back
     *          creditor AVAILABLE    +rounding     the dust the flooring left
     *          SYSTEM/ROUNDING_DIFFERENCE −rounding
     *
     *   GOLD   creditor held bucket  −applied      the metal it no longer owes
     *          creditor AVAILABLE    +applied      is what it kept as payment
     *
     * Σ = 0 for both assets, which is what postGroup() asserts before it
     * returns. The rounding legs are the interesting pair: the creditor's claim
     * was 5,000,000,000 rial and the metal it kept is worth 4,999,979,000, so
     * without them the creditor would silently be 21,000 rial short. Whole
     * milligrams cannot express that difference, so the platform's rounding
     * account absorbs it — that is the account's entire purpose (§3.4) and it
     * is cheaper and more honest than leaving a 21,000-rial stub obligation
     * that costs more to chase than it is worth.
     */
    private function postCross(
        CrossSettlementModel $cross,
        SettlementModel $rial,
        SettlementModel $gold,
        CrossSettlementTerms $terms,
    ): string {
        $ref = LedgerReference::of('cross_settlement', (int) $cross->id);
        $rialBucket = $rial->heldBucket();
        $goldBucket = $gold->heldBucket();

        return $this->ledger->postGroup(function (GroupWriter $writer) use (
            $cross,
            $terms,
            $ref,
            $rialBucket,
            $goldBucket,
        ): void {
            $writer->post(
                $cross->debtor_org_id,
                AssetType::RIAL,
                $rialBucket,
                -$terms->rialDischargedRial,
                EntryType::SETTLEMENT_RELEASE,
                $ref,
                'Cross settlement: rial obligation discharged in gold',
            );

            $writer->post(
                $cross->debtor_org_id,
                AssetType::RIAL,
                Bucket::AVAILABLE,
                $terms->rialDischargedRial,
                EntryType::SETTLEMENT_RELEASE,
                $ref,
            );

            $writer->post(
                $cross->creditor_org_id,
                AssetType::GOLD,
                $goldBucket,
                -$terms->goldAppliedMg,
                EntryType::SETTLEMENT_RELEASE,
                $ref,
                'Cross settlement: gold retained in payment of a rial claim',
            );

            $writer->post(
                $cross->creditor_org_id,
                AssetType::GOLD,
                Bucket::AVAILABLE,
                $terms->goldAppliedMg,
                EntryType::SETTLEMENT_RELEASE,
                $ref,
            );

            if ($terms->roundingRial > 0) {
                $writer->post(
                    $cross->creditor_org_id,
                    AssetType::RIAL,
                    Bucket::AVAILABLE,
                    $terms->roundingRial,
                    EntryType::ROUNDING,
                    $ref,
                    'Cross settlement rounding: whole-milligram remainder',
                );

                $writer->postSystem(
                    SystemAccountCode::ROUNDING_DIFFERENCE,
                    AssetType::RIAL,
                    -$terms->roundingRial,
                    EntryType::ROUNDING,
                    $ref,
                );
            }
        })->value;
    }

    /**
     * Shrink both obligations by what the cross discharged.
     *
     * Only the locked_* figures move. fine_weight_mg and cash_amount_rial are
     * the historical terms of the trade the settlement came from and stay as
     * they were; what is still owed is what is still held, and every downstream
     * side effect in SettlementStateMachine already reads the locked columns
     * rather than the trade's. Leaving the trade figures alone also keeps the
     * table's own CHECK constraints (locked ≤ agreed, weight > 0) true without
     * special cases when a cross consumes an obligation entirely.
     *
     * The rial settlement is walked to PAYMENT_CONFIRMED once nothing is left
     * locked on it, with the ledger effects switched off: the payment was made
     * — in metal — and this service has already posted it.
     */
    private function applyToSettlements(
        CrossSettlementModel $cross,
        SettlementModel $rial,
        SettlementModel $gold,
        CrossSettlementTerms $terms,
        string $group,
        ?int $actorUserId,
    ): void {
        $rial->locked_cash_rial = IntMath::sub($rial->locked_cash_rial, $terms->rialDischargedRial);
        $rial->save();

        $gold->locked_gold_mg = IntMath::sub($gold->locked_gold_mg, $terms->goldAppliedMg);
        $gold->save();

        if ($rial->locked_cash_rial > 0) {
            return;
        }

        $reason = 'Discharged by cross settlement '.$cross->cross_code;

        $context = ($actorUserId === null
            ? TransitionContext::system($reason)
            : TransitionContext::user($actorUserId, $reason))
            ->withMetadata([
                'cross_settlement_id' => (int) $cross->id,
                'agreed_rate_rial' => $cross->agreed_rate_rial,
            ])
            ->withLedgerAlreadyPosted($group);

        if ($rial->status === SettlementStatus::PAYMENT_PENDING
            || $rial->status === SettlementStatus::OVERDUE
        ) {
            $this->stateMachine->transition((int) $rial->id, SettlementStatus::PAYMENT_DECLARED, $context);
        }

        $this->stateMachine->transition((int) $rial->id, SettlementStatus::PAYMENT_CONFIRMED, $context);

        $confirmed = $this->stateMachine->lock((int) $rial->id);
        $confirmed->payment_confirmed_at = now();
        $confirmed->save();
    }

    // ── validation and locking ───────────────────────────────────────────────

    private function assertProposable(
        SettlementModel $rial,
        SettlementModel $gold,
        PricePerFineGram $agreedRate,
        int $proposedByOrgId,
    ): void {
        if ($agreedRate->rial <= 0) {
            throw new OperationNotPermittedException('A cross settlement needs a positive agreed rate');
        }

        if (! in_array($rial->status, self::CROSSABLE_RIAL_STATUSES, true)) {
            throw new OperationNotPermittedException(
                'Settlement '.$rial->settlement_code.' is '.$rial->status->value
                    .' and its cash obligation is not outstanding'
            );
        }

        if (! in_array($gold->status, self::CROSSABLE_GOLD_STATUSES, true)) {
            throw new OperationNotPermittedException(
                'Settlement '.$gold->settlement_code.' is '.$gold->status->value
                    .' and is not holding deliverable gold'
            );
        }

        if ($rial->locked_cash_rial <= 0) {
            throw new OperationNotPermittedException(
                'Settlement '.$rial->settlement_code.' has no locked cash to cross'
            );
        }

        if ($gold->locked_gold_mg <= 0) {
            throw new OperationNotPermittedException(
                'Settlement '.$gold->settlement_code.' has no locked gold to cross'
            );
        }

        // The obligations must point in opposite directions between the same
        // two members: A owes B cash, B owes A metal. Anything else is not a
        // cross, it is two unrelated debts.
        if ($gold->gold_deliverer_org_id !== $rial->cash_receiver_org_id
            || $gold->gold_receiver_org_id !== $rial->cash_payer_org_id
        ) {
            throw new OperationNotPermittedException(
                'A cross settlement needs opposing obligations between the same two members'
            );
        }

        if ($proposedByOrgId !== $rial->cash_payer_org_id && $proposedByOrgId !== $rial->cash_receiver_org_id) {
            throw new OperationNotPermittedException(
                'Only a party to the obligations may propose a cross settlement'
            );
        }

        $conflict = CrossSettlementModel::query()
            ->open()
            ->where(function ($query) use ($rial, $gold): void {
                $query->where('rial_settlement_id', $rial->id)
                    ->orWhere('gold_settlement_id', $gold->id);
            })
            ->exists();

        if ($conflict) {
            throw new OperationNotPermittedException(
                'One of these settlements is already part of an open cross settlement'
            );
        }
    }

    private function assertStillCrossable(SettlementModel $rial, SettlementModel $gold): void
    {
        if (! in_array($rial->status, self::CROSSABLE_RIAL_STATUSES, true)
            || ! in_array($gold->status, self::CROSSABLE_GOLD_STATUSES, true)
        ) {
            throw new OperationNotPermittedException(
                'One of the settlements changed state after the cross was agreed'
            );
        }

        if ($rial->locked_cash_rial <= 0 || $gold->locked_gold_mg <= 0) {
            throw new OperationNotPermittedException(
                'One of the obligations was already discharged elsewhere'
            );
        }
    }

    /**
     * Both settlements, locked in ascending id order (AGENT_BRIEF rule 4).
     *
     * @return array{0: SettlementModel, 1: SettlementModel} [rial side, gold side]
     */
    private function lockPair(int $rialSettlementId, int $goldSettlementId): array
    {
        if ($rialSettlementId === $goldSettlementId) {
            throw new OperationNotPermittedException(
                'A settlement cannot be crossed against itself'
            );
        }

        $ids = [$rialSettlementId, $goldSettlementId];
        sort($ids);

        /** @var array<int, SettlementModel> $locked */
        $locked = [];

        foreach ($ids as $id) {
            $locked[$id] = $this->stateMachine->lock($id);
        }

        return [$locked[$rialSettlementId], $locked[$goldSettlementId]];
    }

    private function lockCross(int $crossId): CrossSettlementModel
    {
        /** @var ?CrossSettlementModel $cross */
        $cross = CrossSettlementModel::query()->whereKey($crossId)->lockForUpdate()->first();

        if (! $cross instanceof CrossSettlementModel) {
            throw new OperationNotPermittedException('Cross settlement '.$crossId.' does not exist');
        }

        return $cross;
    }

    public static function codeFor(int $crossId): string
    {
        return sprintf('CRS-%08d', $crossId);
    }
}
