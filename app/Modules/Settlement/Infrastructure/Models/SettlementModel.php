<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Infrastructure\Models;

use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Settlement\Contracts\SettlementSnapshot;
use App\Modules\Settlement\Domain\DeliveryMethod;
use App\Modules\Settlement\Domain\PaymentMethod;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Domain\SettlementType;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $settlement_code
 * @property int $trade_id
 * @property SettlementStatus $status
 * @property SettlementType $settlement_type
 * @property DeliveryMethod $delivery_method
 * @property PaymentMethod $payment_method
 * @property int $gold_deliverer_org_id
 * @property int $gold_receiver_org_id
 * @property int $cash_payer_org_id
 * @property int $cash_receiver_org_id
 * @property int $fine_weight_mg
 * @property int $cash_amount_rial
 * @property int $buyer_fee_rial
 * @property int $seller_fee_rial
 * @property int $locked_gold_mg
 * @property int $locked_cash_rial
 * @property ?string $held_bucket
 * @property int $penalty_rial
 * @property int $escalation_level
 * @property int $version
 */
final class SettlementModel extends Model
{
    protected $table = 'settlements';

    protected $guarded = [];

    protected $casts = [
        'status' => SettlementStatus::class,
        'settlement_type' => SettlementType::class,
        'delivery_method' => DeliveryMethod::class,
        'payment_method' => PaymentMethod::class,
        'allocated_lot_ids' => 'array',
        'trade_id' => 'int',
        'gold_deliverer_org_id' => 'int',
        'gold_receiver_org_id' => 'int',
        'cash_payer_org_id' => 'int',
        'cash_receiver_org_id' => 'int',
        'fine_weight_mg' => 'int',
        'cash_amount_rial' => 'int',
        'buyer_fee_rial' => 'int',
        'seller_fee_rial' => 'int',
        'locked_gold_mg' => 'int',
        'locked_cash_rial' => 'int',
        'penalty_rial' => 'int',
        'escalation_level' => 'int',
        'version' => 'int',
        'netting_batch_id' => 'int',
        'dispute_id' => 'int',
        'parent_settlement_id' => 'int',
        'partial_of_rial' => 'int',
        'gold_reservation_entry_id' => 'int',
        'cash_reservation_entry_id' => 'int',
        'gold_confirmed_by_user_id' => 'int',
        'reversal_requested_by_user_id' => 'int',
        'reversal_approved_by_user_id' => 'int',
        'deadline_at' => 'datetime',
        'overdue_since' => 'datetime',
        'penalty_accrued_at' => 'datetime',
        'settled_at' => 'datetime',
        'completed_at' => 'datetime',
        'reversed_at' => 'datetime',
        'payment_declared_at' => 'datetime',
        'payment_confirmed_at' => 'datetime',
        'auto_matched_at' => 'datetime',
        'gold_transferred_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ── derived values ───────────────────────────────────────────────────────

    /** F9 — gross plus the buyer's fee: what the payer owes and what is locked. */
    public function totalCashDue(): Rial
    {
        return Rial::fromRial($this->cash_amount_rial + $this->buyer_fee_rial);
    }

    /** F9 — gross minus the seller's fee: what actually lands in their account. */
    public function sellerProceeds(): Rial
    {
        return Rial::fromRial($this->cash_amount_rial - $this->seller_fee_rial);
    }

    public function fine(): FineWeight
    {
        return FineWeight::fromMilligrams($this->fine_weight_mg);
    }

    /** Where this settlement's locked assets currently sit. */
    public function heldBucket(): Bucket
    {
        return $this->held_bucket === Bucket::IN_DISPUTE->value
            ? Bucket::IN_DISPUTE
            : Bucket::IN_SETTLEMENT;
    }

    public function hasLockedAssets(): bool
    {
        return $this->locked_gold_mg > 0 || $this->locked_cash_rial > 0;
    }

    /** @return list<int> */
    public function lotIds(): array
    {
        /** @var array<int, mixed>|null $ids */
        $ids = $this->allocated_lot_ids;

        return array_values(array_map('intval', $ids ?? []));
    }

    // ── scopes ───────────────────────────────────────────────────────────────

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', array_map(
            static fn (SettlementStatus $s): string => $s->value,
            array_filter(SettlementStatus::cases(), static fn (SettlementStatus $s): bool => $s->isOpen()),
        ));
    }

    /** @param Builder<self> $query */
    public function scopeAwaitingPayment(Builder $query): void
    {
        $query->whereIn('status', array_map(
            static fn (SettlementStatus $s): string => $s->value,
            array_filter(
                SettlementStatus::cases(),
                static fn (SettlementStatus $s): bool => $s->isAwaitingPayment(),
            ),
        ));
    }

    /** @param Builder<self> $query */
    public function scopeForOrganization(Builder $query, int $organizationId): void
    {
        $query->where(function (Builder $q) use ($organizationId): void {
            $q->where('gold_deliverer_org_id', $organizationId)
                ->orWhere('gold_receiver_org_id', $organizationId)
                ->orWhere('cash_payer_org_id', $organizationId)
                ->orWhere('cash_receiver_org_id', $organizationId);
        });
    }

    public function toSnapshot(): SettlementSnapshot
    {
        return new SettlementSnapshot(
            id: (int) $this->id,
            settlementCode: (string) $this->settlement_code,
            tradeId: $this->trade_id,
            settlementType: $this->settlement_type->value,
            status: $this->status->value,
            goldDelivererOrgId: $this->gold_deliverer_org_id,
            goldReceiverOrgId: $this->gold_receiver_org_id,
            cashPayerOrgId: $this->cash_payer_org_id,
            cashReceiverOrgId: $this->cash_receiver_org_id,
            fineWeightMg: $this->fine_weight_mg,
            cashAmountRial: $this->cash_amount_rial,
            buyerFeeRial: $this->buyer_fee_rial,
            sellerFeeRial: $this->seller_fee_rial,
            deliveryMethod: $this->delivery_method->value,
            paymentMethod: $this->payment_method->value,
            allocatedLotIds: $this->lotIds(),
            deadlineAt: (string) $this->deadline_at?->toIso8601String(),
            settledAt: $this->settled_at?->toIso8601String(),
            completedAt: $this->completed_at?->toIso8601String(),
            overdueSince: $this->overdue_since?->toIso8601String(),
            penaltyRial: $this->penalty_rial,
            nettingBatchId: $this->netting_batch_id === null ? null : (int) $this->netting_batch_id,
            parentSettlementId: $this->parent_settlement_id === null ? null : (int) $this->parent_settlement_id,
        );
    }
}
