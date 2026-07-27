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
use App\Modules\Settlement\Contracts\LotMovementPort;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Domain\TransitionContext;
use App\Modules\Settlement\Events\SettlementReversed;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Facades\DB;

/**
 * Correction of a completed settlement — docs/03-domain/05-settlement.md §5.8.
 *
 * Four reasons for it: a dispute decided against the settlement, an operator
 * error, a fraudulent trade found later, or a lot allocated wrongly.
 *
 * Two rules govern how it is done, and they are the whole of this class:
 *
 *   Dual control. §5.8 requires a SETTLEMENT_OFFICER to request and a
 *   PLATFORM_ADMIN to approve. Requester and approver must be different people,
 *   and this refuses the operation outright if they are not. Which roles those
 *   users hold is Identity's judgement, not Settlement's; the invariant that
 *   two distinct humans signed off is enforced here.
 *
 *   Nothing is ever deleted. "رکورد اصلی Trade و Settlement هرگز حذف یا ویرایش
 *   نمی‌شود." The settlement row keeps every original figure — weight, amounts,
 *   fees, lot ids, timestamps — and gains a status of REVERSED plus the
 *   reversal audit columns. The ledger gains a mirror-image transaction_group,
 *   never an UPDATE (AGENT_BRIEF rule 2).
 *
 * The mirror group is posted here rather than by the state machine because the
 * dual-control identities have to be recorded with it, and because it is the
 * inverse of two earlier groups rather than a consequence of the transition
 * itself. It balances by construction:
 *
 *     −(gross − seller_fee) + (gross + buyer_fee) − buyer_fee − seller_fee = 0
 */
final readonly class ReversalService
{
    public function __construct(
        private SettlementStateMachine $stateMachine,
        private LedgerInterface $ledger,
        private LotMovementPort $lotMovement,
    ) {}

    /**
     * Reverse a settled settlement under dual control.
     *
     * @param  bool  $returnLots  put the lots back with the original owner as well
     *                            as reversing the ledger. §5.8 step 4 allows a cash
     *                            equivalent instead when the lot is no longer
     *                            identifiable.
     */
    public function reverse(
        int $settlementId,
        string $reason,
        int $requestedByUserId,
        int $approvedByUserId,
        bool $returnLots = true,
    ): SettlementModel {
        $this->assertDualControl($reason, $requestedByUserId, $approvedByUserId);

        /** @var array{settlement: SettlementModel, from: string, group: ?string, lots: bool} $result */
        $result = DB::transaction(function () use (
            $settlementId,
            $reason,
            $requestedByUserId,
            $approvedByUserId,
            $returnLots,
        ): array {
            $settlement = $this->stateMachine->lock($settlementId);
            $from = $settlement->status;

            $this->assertReversible($settlement);

            $group = $this->postMirrorGroup($settlement, $reason);

            $lotsReturned = false;

            if ($returnLots && $settlement->lotIds() !== []) {
                $this->lotMovement->returnToOwner(
                    $settlement->lotIds(),
                    $settlement->gold_receiver_org_id,
                    $settlement->gold_deliverer_org_id,
                    $settlementId,
                    $approvedByUserId,
                );
                $lotsReturned = $this->lotMovement->isOperational();
            }

            $this->stateMachine->transition(
                $settlementId,
                SettlementStatus::REVERSED,
                (new TransitionContext(
                    actorUserId: $approvedByUserId,
                    reason: $reason,
                    metadata: [
                        'requested_by_user_id' => $requestedByUserId,
                        'approved_by_user_id' => $approvedByUserId,
                        'reversed_from' => $from->value,
                        'lots_returned' => $lotsReturned,
                    ],
                ))->withLedgerAlreadyPosted($group),
            );

            // Audit columns only. Every original figure is left exactly as it
            // was — §5.8 forbids editing the record.
            $fresh = $this->stateMachine->lock($settlementId);
            $fresh->reversal_reason = $reason;
            $fresh->reversal_requested_by_user_id = $requestedByUserId;
            $fresh->reversal_approved_by_user_id = $approvedByUserId;
            // The mirror group already returned anything that was quarantined.
            $fresh->locked_gold_mg = 0;
            $fresh->locked_cash_rial = 0;
            $fresh->held_bucket = null;
            $fresh->save();

            return ['settlement' => $fresh, 'from' => $from->value, 'group' => $group, 'lots' => $lotsReturned];
        }, attempts: 3);

        $settlement = $result['settlement'];

        event(new SettlementReversed(
            settlementId: (int) $settlement->id,
            settlementCode: (string) $settlement->settlement_code,
            fromStatus: $result['from'],
            goldDelivererOrgId: $settlement->gold_deliverer_org_id,
            goldReceiverOrgId: $settlement->gold_receiver_org_id,
            fineWeightMg: $settlement->fine_weight_mg,
            cashAmountRial: $settlement->cash_amount_rial,
            reason: $result['from'] === SettlementStatus::DISPUTED->value
                ? 'Dispute resolution: '.$settlement->reversal_reason
                : (string) $settlement->reversal_reason,
            requestedByUserId: (int) $settlement->reversal_requested_by_user_id,
            approvedByUserId: (int) $settlement->reversal_approved_by_user_id,
            transactionGroup: $result['group'],
            lotsReturned: $result['lots'],
            occurredAt: now()->toIso8601String(),
        ));

        return $settlement;
    }

    /**
     * The mirror image of the settlement's cash and gold movements, in one
     * balanced group with EntryType::REVERSAL.
     *
     * Deliberately not LedgerInterface::reverse(): that reverses one entry at a
     * time against SUSPENSE, which is right for correcting a single stray row
     * but would leave SUSPENSE holding the settlement's value between calls.
     * A settlement is reversed whole or not at all, so it is posted whole.
     */
    private function postMirrorGroup(SettlementModel $s, string $reason): ?string
    {
        // What actually moved, read from the timestamps rather than inferred
        // from the status: a DISPUTED settlement may have delivered the gold
        // and never been paid, or the other way round, and reversing a leg
        // that never ran would invent value.
        $goldMoved = $s->gold_transferred_at !== null;
        $cashMoved = $s->payment_confirmed_at !== null;
        $stillHeld = $s->hasLockedAssets();

        if (! $goldMoved && ! $cashMoved && ! $stillHeld) {
            return null;
        }

        $ref = LedgerReference::settlement((int) $s->id);
        $description = 'Reversal: '.$reason;
        $heldBucket = $s->heldBucket();

        $group = $this->ledger->postGroup(function (GroupWriter $writer) use (
            $s, $ref, $description, $goldMoved, $cashMoved, $heldBucket
        ): void {
            // Anything still quarantined goes home first: a reversal ends with
            // both parties whole, whichever legs had run.
            if ($s->locked_gold_mg > 0) {
                $writer->post(
                    $s->gold_deliverer_org_id, AssetType::GOLD, $heldBucket,
                    -$s->locked_gold_mg, EntryType::REVERSAL, $ref, $description,
                );
                $writer->post(
                    $s->gold_deliverer_org_id, AssetType::GOLD, Bucket::AVAILABLE,
                    $s->locked_gold_mg, EntryType::REVERSAL, $ref, $description,
                );
            }

            if ($s->locked_cash_rial > 0) {
                $writer->post(
                    $s->cash_payer_org_id, AssetType::RIAL, $heldBucket,
                    -$s->locked_cash_rial, EntryType::REVERSAL, $ref, $description,
                );
                $writer->post(
                    $s->cash_payer_org_id, AssetType::RIAL, Bucket::AVAILABLE,
                    $s->locked_cash_rial, EntryType::REVERSAL, $ref, $description,
                );
            }

            if ($goldMoved && $s->fine_weight_mg > 0) {
                $writer->post(
                    $s->gold_receiver_org_id, AssetType::GOLD, Bucket::AVAILABLE,
                    -$s->fine_weight_mg, EntryType::REVERSAL, $ref, $description,
                );
                $writer->post(
                    $s->gold_deliverer_org_id, AssetType::GOLD, Bucket::AVAILABLE,
                    $s->fine_weight_mg, EntryType::REVERSAL, $ref, $description,
                );
            }

            if (! $cashMoved) {
                return;
            }

            $proceeds = $s->sellerProceeds()->amount;
            $due = $s->totalCashDue()->amount;

            if ($proceeds > 0) {
                $writer->post(
                    $s->cash_receiver_org_id, AssetType::RIAL, Bucket::AVAILABLE,
                    -$proceeds, EntryType::REVERSAL, $ref, $description,
                );
            }

            if ($due > 0) {
                $writer->post(
                    $s->cash_payer_org_id, AssetType::RIAL, Bucket::AVAILABLE,
                    $due, EntryType::REVERSAL, $ref, $description,
                );
            }

            foreach ([$s->buyer_fee_rial, $s->seller_fee_rial] as $fee) {
                if ($fee > 0) {
                    $writer->postSystem(
                        SystemAccountCode::FEE_INCOME, AssetType::RIAL,
                        -$fee, EntryType::REVERSAL, $ref, $description,
                    );
                }
            }
        });

        return $group->value;
    }

    private function assertDualControl(string $reason, int $requestedBy, int $approvedBy): void
    {
        if (trim($reason) === '') {
            throw new OperationNotPermittedException('A reversal must state a reason');
        }

        if ($requestedBy <= 0 || $approvedBy <= 0) {
            throw new OperationNotPermittedException(
                'A reversal must name both the requesting and the approving user'
            );
        }

        if ($requestedBy === $approvedBy) {
            throw new OperationNotPermittedException(
                'A reversal must be requested and approved by two different users'
            );
        }
    }

    private function assertReversible(SettlementModel $settlement): void
    {
        if (! $settlement->status->canTransitionTo(SettlementStatus::REVERSED)) {
            throw new OperationNotPermittedException(
                'A settlement in '.$settlement->status->value.' cannot be reversed'
            );
        }
    }
}
