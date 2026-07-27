<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application;

use App\Modules\Dispute\Contracts\DisputeHoldPort;
use App\Modules\Dispute\Domain\DisputeDecision;
use App\Modules\Dispute\Domain\DisputeParty;
use App\Modules\Dispute\Domain\DisputeStatus;
use App\Modules\Dispute\Events\DisputeReputationAssessed;
use App\Modules\Dispute\Events\DisputeResolved;
use App\Modules\Dispute\Infrastructure\Models\DisputeModel;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Recording a verdict and carrying it out — §13.7.
 *
 * The sequence follows the sample `executeDecision()` in the document:
 * release the holds, transfer any award, mark the case EXECUTED, and only then
 * announce it.
 *
 * Two design points worth spelling out:
 *
 *  1. **Releasing before transferring.** The disputed amount sits in the
 *     respondent's IN_DISPUTE bucket. A transfer moves value out of AVAILABLE,
 *     so the hold has to come back first or the award would be paid from money
 *     the respondent appears not to have. Doing it in the other order is the
 *     kind of bug that only shows up when the respondent's balance is thin.
 *
 *  2. **Reputation is announced, not applied.** §13.8 describes score changes
 *     — «credit_score −80» — but this module emits DisputeReputationAssessed
 *     and lets Reputation decide. A dispute workflow that also wrote credit
 *     scores would couple two policies that change for unrelated reasons, and
 *     Reputation is not even in this module's permitted dependencies.
 */
final readonly class ResolutionService
{
    public function __construct(
        private DisputeStateMachine $stateMachine,
        private DisputeHoldPort $holds,
    ) {}

    /**
     * Record the verdict (RESOLVED) and execute it (EXECUTED) in one call.
     *
     * @param  int  $awardedGoldMg  signed: positive means the claimant receives
     * @param  int  $awardedRial  signed: positive means the claimant receives
     */
    public function decide(
        DisputeModel $dispute,
        DisputeDecision $decision,
        int $awardedGoldMg = 0,
        int $awardedRial = 0,
        string $rationale = '',
        ?int $decidedByUserId = null,
        ?TransitionContext $actor = null,
        bool $frivolous = false,
    ): DisputeModel {
        if (! $decision->permitsAward() && ($awardedGoldMg !== 0 || $awardedRial !== 0)) {
            throw new InvalidArgumentException(
                $decision->value.' cannot carry an award: '.$decision->execution()
            );
        }

        if ($frivolous && ! $decision->canBeFrivolous()) {
            throw new InvalidArgumentException('Only a rejected claim can be marked frivolous');
        }

        $context = $actor ?? TransitionContext::system('DECISION_ISSUED', $rationale);

        $dispute->update([
            'decision' => $decision->value,
            'decision_rationale' => $rationale,
            'decided_at' => Carbon::now(),
            'decided_by_user_id' => $decidedByUserId,
            'is_frivolous' => $frivolous,
            'awarded_gold_mg' => $awardedGoldMg,
            'awarded_rial' => $awardedRial,
        ]);

        $this->stateMachine->transition(
            $dispute,
            DisputeStatus::RESOLVED,
            $context->withAction($context->action !== '' ? $context->action : 'DECISION_ISSUED'),
        );

        return $this->execute($dispute);
    }

    /**
     * Carry out whatever verdict is already recorded.
     *
     * Separate from decide() because a verdict reached by agreement, by
     * acceptance or by timeout all arrive here from different places, and
     * because an execution that failed part-way must be retryable.
     */
    public function execute(DisputeModel $dispute): DisputeModel
    {
        $status = $dispute->statusEnum();

        if ($status === DisputeStatus::EXECUTED) {
            return $dispute;
        }

        if ($status !== DisputeStatus::RESOLVED && $status !== DisputeStatus::WITHDRAWN) {
            throw new OperationNotPermittedException(
                'Only a resolved or withdrawn case can be executed, not one that is '.$status->label()
            );
        }

        $decision = $dispute->decision === null ? null : DisputeDecision::from($dispute->decision);

        $disputeId = (int) $dispute->id;

        DB::transaction(function () use ($dispute, $disputeId): void {
            // 1) Release the holds first — see the class comment.
            $this->releaseHolds($dispute, $disputeId);

            // 2) Move whatever the verdict awards.
            $this->applyAward($dispute, $disputeId);
        });

        // A withdrawn case is already final and cannot transition again; the
        // release above is all its execution consists of.
        if ($dispute->statusEnum() === DisputeStatus::RESOLVED) {
            $this->stateMachine->transition(
                $dispute,
                DisputeStatus::EXECUTED,
                TransitionContext::system('DECISION_EXECUTED', $decision?->execution()),
            );
        }

        $this->announce($dispute, $decision);

        return $dispute;
    }

    /**
     * A withdrawal is its own verdict: the lock comes off and nothing else
     * happens («WITHDRAWN — آزادسازی قفل»).
     */
    public function executeWithdrawal(DisputeModel $dispute): DisputeModel
    {
        $dispute->update([
            'decision' => DisputeDecision::WITHDRAWN->value,
            'decided_at' => Carbon::now(),
            'awarded_gold_mg' => 0,
            'awarded_rial' => 0,
        ]);

        return $this->execute($dispute);
    }

    private function releaseHolds(DisputeModel $dispute, int $disputeId): void
    {
        if ($dispute->hold_released) {
            return;
        }

        if ($dispute->claim_gold_mg > 0) {
            $this->holds->releaseGoldHold(
                $dispute->respondent_org_id,
                $dispute->claim_gold_mg,
                $disputeId,
                $dispute->hold_gold_entry_id,
            );
        }

        if ($dispute->claim_rial > 0) {
            $this->holds->releaseRialHold(
                $dispute->respondent_org_id,
                $dispute->claim_rial,
                $disputeId,
                $dispute->hold_rial_entry_id,
            );
        }

        $dispute->update(['hold_released' => true]);
    }

    private function applyAward(DisputeModel $dispute, int $disputeId): void
    {
        $gold = $dispute->awarded_gold_mg;
        $rial = $dispute->awarded_rial;

        if ($gold !== 0) {
            $this->holds->transferGold(
                fromOrgId: $gold > 0 ? $dispute->respondent_org_id : $dispute->claimant_org_id,
                toOrgId: $gold > 0 ? $dispute->claimant_org_id : $dispute->respondent_org_id,
                fineMg: abs($gold),
                disputeId: $disputeId,
            );
        }

        if ($rial !== 0) {
            $this->holds->transferRial(
                fromOrgId: $rial > 0 ? $dispute->respondent_org_id : $dispute->claimant_org_id,
                toOrgId: $rial > 0 ? $dispute->claimant_org_id : $dispute->respondent_org_id,
                rial: abs($rial),
                disputeId: $disputeId,
            );
        }
    }

    /** Both announcements, after the transaction (AGENT_BRIEF rule 3). */
    private function announce(DisputeModel $dispute, ?DisputeDecision $decision): void
    {
        event(new DisputeResolved(
            disputeId: (int) $dispute->id,
            caseNumber: $dispute->case_number,
            decision: $decision?->value ?? DisputeDecision::WITHDRAWN->value,
            claimantOrgId: $dispute->claimant_org_id,
            respondentOrgId: $dispute->respondent_org_id,
            awardedGoldMg: $dispute->awarded_gold_mg,
            awardedRial: $dispute->awarded_rial,
            tradeId: $dispute->trade_id,
            goldLotId: $dispute->gold_lot_id,
        ));

        if ($decision === null) {
            return;
        }

        $loser = $decision->reputationLoser();

        event(new DisputeReputationAssessed(
            disputeId: (int) $dispute->id,
            caseNumber: $dispute->case_number,
            decision: $decision->value,
            losingParty: $loser?->value,
            losingOrgId: match ($loser) {
                DisputeParty::CLAIMANT => $dispute->claimant_org_id,
                DisputeParty::RESPONDENT => $dispute->respondent_org_id,
                default => null,
            },
            claimantOrgId: $dispute->claimant_org_id,
            respondentOrgId: $dispute->respondent_org_id,
            isFrivolousClaim: (bool) $dispute->is_frivolous,
            isPlatformFault: $decision->isPlatformLiable(),
        ));
    }
}
