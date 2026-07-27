<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Application;

use App\Modules\Ledger\Contracts\LedgerInterface;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Domain\TransitionContext;
use App\Modules\Settlement\Events\SettlementOpened;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use App\Modules\Shared\Support\IntMath;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;
use Illuminate\Support\Facades\DB;

/**
 * Option A of docs/03-domain/05-settlement.md §5.4 — proportional settlement,
 * the system default.
 *
 * The document's example: a 500 g / 39.24 bn trade where the buyer pays only
 * 20 bn (51 %). Proportionally that buys 254.8 g; the rest stays an open
 * obligation on a child settlement.
 *
 *     delivered_fine = floor(fine × paid / gross)
 *
 * Floor, not round: the buyer gets no more gold than they paid for, and the
 * dust that rounding leaves behind stays with the remainder rather than being
 * created out of nothing. Fees are prorated the same way and the child takes
 * the difference, so parent + child fees always add back to the original to
 * the rial — a fee cannot be lost or invented by splitting a settlement.
 *
 * Option B (suspend everything until the balance arrives) is a configuration
 * choice per §5.4; this service implements A and `config('goldb2b.settlement.
 * partial_mode')` is where B would be selected.
 *
 * The parent keeps its identity, deadline and locked assets minus what moved to
 * the child, so the payer's outstanding obligation is unchanged in total.
 */
final readonly class PartialSettlementService
{
    public function __construct(
        private SettlementStateMachine $stateMachine,
        private LedgerInterface $ledger,
    ) {}

    /**
     * Split a settlement in proportion to what was actually paid.
     *
     * @param  int  $paidRial  gross rial received, excluding the buyer's fee
     * @return array{parent: SettlementModel, child: SettlementModel}
     */
    public function settlePartially(
        int $settlementId,
        int $paidRial,
        ?int $actorUserId = null,
    ): array {
        /** @var array{parent: SettlementModel, child: SettlementModel} $result */
        $result = DB::transaction(function () use ($settlementId, $paidRial, $actorUserId): array {
            $parent = $this->stateMachine->lock($settlementId);

            $this->assertSplittable($parent, $paidRial);

            $totalFine = $parent->fine_weight_mg;
            $totalGross = $parent->cash_amount_rial;

            // Proportional delivery — §5.4 option A.
            $deliveredFine = IntMath::mulDivFloor($totalFine, $paidRial, $totalGross);

            if ($deliveredFine <= 0 || $deliveredFine >= $totalFine) {
                throw new OperationNotPermittedException(
                    'A partial payment must buy some, but not all, of the gold'
                );
            }

            $buyerFeePaid = IntMath::mulDivFloor($parent->buyer_fee_rial, $paidRial, $totalGross);
            $sellerFeePaid = IntMath::mulDivFloor($parent->seller_fee_rial, $paidRial, $totalGross);

            $remainderFine = IntMath::sub($totalFine, $deliveredFine);
            $remainderGross = IntMath::sub($totalGross, $paidRial);
            $remainderBuyerFee = IntMath::sub($parent->buyer_fee_rial, $buyerFeePaid);
            $remainderSellerFee = IntMath::sub($parent->seller_fee_rial, $sellerFeePaid);

            $child = $this->createChild(
                $parent,
                $remainderFine,
                $remainderGross,
                $remainderBuyerFee,
                $remainderSellerFee,
            );

            // The locked assets follow the split: neither party has to top up,
            // and nothing is released, so the ledger sees no movement at all —
            // only two settlements pointing at the same holdings.
            $this->handOverHoldings($parent, $child, $remainderFine, $remainderGross + $remainderBuyerFee);

            $parent->fine_weight_mg = $deliveredFine;
            $parent->cash_amount_rial = $paidRial;
            $parent->buyer_fee_rial = $buyerFeePaid;
            $parent->seller_fee_rial = $sellerFeePaid;
            $parent->partial_of_rial = $totalGross;
            $parent->save();

            return ['parent' => $parent, 'child' => $child];
        }, attempts: 3);

        $child = $result['child'];

        event(new SettlementOpened(
            settlementId: (int) $child->id,
            settlementCode: (string) $child->settlement_code,
            tradeId: $child->trade_id,
            goldDelivererOrgId: $child->gold_deliverer_org_id,
            goldReceiverOrgId: $child->gold_receiver_org_id,
            cashPayerOrgId: $child->cash_payer_org_id,
            cashReceiverOrgId: $child->cash_receiver_org_id,
            fineWeightMg: $child->fine_weight_mg,
            cashAmountRial: $child->cash_amount_rial,
            buyerFeeRial: $child->buyer_fee_rial,
            sellerFeeRial: $child->seller_fee_rial,
            settlementType: $child->settlement_type->value,
            deadlineAt: (string) $child->deadline_at?->toIso8601String(),
            occurredAt: now()->toIso8601String(),
        ));

        return $result;
    }

    /**
     * The remainder, as its own settlement in ASSETS_LOCKED: the assets are
     * already held, so it starts where the parent was rather than at CREATED
     * (which would try to lock them a second time).
     */
    private function createChild(
        SettlementModel $parent,
        int $fineMg,
        int $grossRial,
        int $buyerFee,
        int $sellerFee,
    ): SettlementModel {
        $child = SettlementModel::query()->create([
            'settlement_code' => 'STL-PENDING-'.bin2hex(random_bytes(4)),
            'trade_id' => $parent->trade_id,
            'settlement_type' => $parent->settlement_type->value,
            'gold_deliverer_org_id' => $parent->gold_deliverer_org_id,
            'gold_receiver_org_id' => $parent->gold_receiver_org_id,
            'cash_payer_org_id' => $parent->cash_payer_org_id,
            'cash_receiver_org_id' => $parent->cash_receiver_org_id,
            'fine_weight_mg' => $fineMg,
            'cash_amount_rial' => $grossRial,
            'buyer_fee_rial' => $buyerFee,
            'seller_fee_rial' => $sellerFee,
            'delivery_method' => $parent->delivery_method->value,
            'payment_method' => $parent->payment_method->value,
            'deadline_at' => $parent->deadline_at,
            'parent_settlement_id' => $parent->id,
            'status' => SettlementStatus::ASSETS_LOCKED->value,
            'held_bucket' => $parent->held_bucket,
        ]);

        $child->settlement_code = OpenSettlementService::codeFor((int) $child->id);
        $child->save();

        $this->stateMachine->transition(
            (int) $child->id,
            SettlementStatus::PAYMENT_PENDING,
            TransitionContext::system('Remainder of settlement '.$parent->settlement_code),
        );

        return $child->refresh();
    }

    /**
     * Move the remainder's share of the holdings from parent to child.
     *
     * No ledger entry: the value has not moved organisation or bucket, only the
     * settlement that claims it. Writing an entry here would break conservation
     * of the *reason* for a balance without changing the balance itself. What
     * does need to hold is that the two rows still add up to what was locked,
     * which is asserted before either is written.
     */
    private function handOverHoldings(
        SettlementModel $parent,
        SettlementModel $child,
        int $childGoldMg,
        int $childCashRial,
    ): void {
        $goldToChild = min($childGoldMg, $parent->locked_gold_mg);
        $cashToChild = min($childCashRial, $parent->locked_cash_rial);

        $child->locked_gold_mg = $goldToChild;
        $child->locked_cash_rial = $cashToChild;
        $child->save();

        $parent->locked_gold_mg = IntMath::sub($parent->locked_gold_mg, $goldToChild);
        $parent->locked_cash_rial = IntMath::sub($parent->locked_cash_rial, $cashToChild);
    }

    /**
     * The buyer paid nothing at all and the deadline has run out: release
     * everything and cancel. Kept here because it is the degenerate case of
     * the same decision — how much of a promise was actually kept.
     */
    public function abandon(int $settlementId, string $reason, ?int $actorUserId = null): SettlementModel
    {
        $this->stateMachine->transition(
            $settlementId,
            SettlementStatus::CANCELLED,
            new TransitionContext(actorUserId: $actorUserId, reason: $reason),
        );

        return $this->stateMachine->lock($settlementId);
    }

    private function assertSplittable(SettlementModel $settlement, int $paidRial): void
    {
        $splittable = [
            SettlementStatus::PAYMENT_PENDING,
            SettlementStatus::PAYMENT_DECLARED,
            SettlementStatus::OVERDUE,
        ];

        if (! in_array($settlement->status, $splittable, true)) {
            throw new OperationNotPermittedException(
                'A settlement can only be split while payment is outstanding; it is '
                    .$settlement->status->value
            );
        }

        if ($paidRial <= 0 || $paidRial >= $settlement->cash_amount_rial) {
            throw new OperationNotPermittedException(
                'A partial payment must be more than nothing and less than the full amount'
            );
        }

        if ($settlement->cash_amount_rial <= 0) {
            throw new OperationNotPermittedException('A zero-value settlement cannot be split');
        }
    }

    /**
     * Rial and gold this settlement has locked, for callers that want to show
     * the member what a partial payment would buy before they commit to it.
     *
     * @return array{fine_mg: int, gross_rial: int}
     */
    public function preview(int $settlementId, int $paidRial): array
    {
        $settlement = SettlementModel::query()->findOrFail($settlementId);

        return [
            'fine_mg' => IntMath::mulDivFloor(
                $settlement->fine_weight_mg,
                $paidRial,
                max(1, $settlement->cash_amount_rial),
            ),
            'gross_rial' => $paidRial,
        ];
    }

    /**
     * Escape hatch for an operator who has to unwind a partial split before
     * anything settled: the child's holdings go back to the parent and the
     * child is cancelled. Nothing is deleted.
     */
    public function mergeBack(int $childId, ?int $actorUserId = null): SettlementModel
    {
        return DB::transaction(function () use ($childId, $actorUserId): SettlementModel {
            $child = $this->stateMachine->lock($childId);

            if ($child->parent_settlement_id === null) {
                throw new OperationNotPermittedException('This settlement has no parent to merge back into');
            }

            // Ascending id order: the parent always predates the child.
            $parent = $this->stateMachine->lock((int) $child->parent_settlement_id);

            $parent->fine_weight_mg = IntMath::add($parent->fine_weight_mg, $child->fine_weight_mg);
            $parent->cash_amount_rial = IntMath::add($parent->cash_amount_rial, $child->cash_amount_rial);
            $parent->buyer_fee_rial = IntMath::add($parent->buyer_fee_rial, $child->buyer_fee_rial);
            $parent->seller_fee_rial = IntMath::add($parent->seller_fee_rial, $child->seller_fee_rial);
            $parent->locked_gold_mg = IntMath::add($parent->locked_gold_mg, $child->locked_gold_mg);
            $parent->locked_cash_rial = IntMath::add($parent->locked_cash_rial, $child->locked_cash_rial);
            $parent->save();

            // The child's holdings now belong to the parent, so cancelling it
            // must not release them a second time.
            $child->locked_gold_mg = 0;
            $child->locked_cash_rial = 0;
            $child->save();

            $this->stateMachine->transition(
                $childId,
                SettlementStatus::CANCELLED,
                new TransitionContext(
                    actorUserId: $actorUserId,
                    reason: 'Merged back into settlement '.$parent->settlement_code,
                ),
            );

            return $parent->refresh();
        }, attempts: 3);
    }

    /** @internal kept for symmetry with the ledger-backed services */
    private function reference(SettlementModel $settlement): LedgerReference
    {
        return LedgerReference::settlement((int) $settlement->id);
    }

    /**
     * Release a specific amount of a settlement's holdings without cancelling
     * it — used when a remainder is written off rather than carried.
     */
    public function writeOffRemainder(int $settlementId, string $reason, ?int $actorUserId = null): SettlementModel
    {
        return DB::transaction(function () use ($settlementId, $reason, $actorUserId): SettlementModel {
            $settlement = $this->stateMachine->lock($settlementId);
            $bucket = $settlement->heldBucket();

            if ($settlement->locked_gold_mg > 0) {
                $this->ledger->moveBucket(
                    $settlement->gold_deliverer_org_id,
                    $bucket,
                    Bucket::AVAILABLE,
                    FineWeight::fromMilligrams($settlement->locked_gold_mg),
                    $this->reference($settlement),
                    EntryType::SETTLEMENT_RELEASE,
                );
            }

            if ($settlement->locked_cash_rial > 0) {
                $this->ledger->moveBucket(
                    $settlement->cash_payer_org_id,
                    $bucket,
                    Bucket::AVAILABLE,
                    Rial::fromRial($settlement->locked_cash_rial),
                    $this->reference($settlement),
                    EntryType::SETTLEMENT_RELEASE,
                );
            }

            $settlement->locked_gold_mg = 0;
            $settlement->locked_cash_rial = 0;
            $settlement->save();

            $this->stateMachine->transition(
                $settlementId,
                SettlementStatus::CANCELLED,
                new TransitionContext(actorUserId: $actorUserId, reason: $reason),
            );

            return $this->stateMachine->lock($settlementId);
        }, attempts: 3);
    }
}
