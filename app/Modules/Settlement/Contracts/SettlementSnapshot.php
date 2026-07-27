<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Contracts;

/**
 * Immutable read model of a settlement for the modules downstream of this one
 * (Accounting posts vouchers from it, Dispute freezes against it).
 *
 * Scalars only, deliberately not an Eloquent model: nothing outside Settlement
 * may mutate a settlement row (AGENT_BRIEF rule 7).
 */
final readonly class SettlementSnapshot
{
    /** @param list<int> $allocatedLotIds */
    public function __construct(
        public int $id,
        public string $settlementCode,
        public int $tradeId,
        public string $settlementType,
        public string $status,
        public int $goldDelivererOrgId,
        public int $goldReceiverOrgId,
        public int $cashPayerOrgId,
        public int $cashReceiverOrgId,
        public int $fineWeightMg,
        public int $cashAmountRial,
        public int $buyerFeeRial,
        public int $sellerFeeRial,
        public string $deliveryMethod,
        public string $paymentMethod,
        public array $allocatedLotIds,
        public string $deadlineAt,
        public ?string $settledAt,
        public ?string $completedAt,
        public ?string $overdueSince,
        public int $penaltyRial,
        public ?int $nettingBatchId,
        public ?int $parentSettlementId,
    ) {}

    public function totalCashDueRial(): int
    {
        return $this->cashAmountRial + $this->buyerFeeRial;
    }

    public function sellerProceedsRial(): int
    {
        return $this->cashAmountRial - $this->sellerFeeRial;
    }

    public function isNetted(): bool
    {
        return $this->nettingBatchId !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'settlement_code' => $this->settlementCode,
            'trade_id' => $this->tradeId,
            'settlement_type' => $this->settlementType,
            'status' => $this->status,
            'gold_deliverer_org_id' => $this->goldDelivererOrgId,
            'gold_receiver_org_id' => $this->goldReceiverOrgId,
            'cash_payer_org_id' => $this->cashPayerOrgId,
            'cash_receiver_org_id' => $this->cashReceiverOrgId,
            'fine_weight_mg' => $this->fineWeightMg,
            'cash_amount_rial' => $this->cashAmountRial,
            'buyer_fee_rial' => $this->buyerFeeRial,
            'seller_fee_rial' => $this->sellerFeeRial,
            'delivery_method' => $this->deliveryMethod,
            'payment_method' => $this->paymentMethod,
            'allocated_lot_ids' => $this->allocatedLotIds,
            'deadline_at' => $this->deadlineAt,
            'settled_at' => $this->settledAt,
            'completed_at' => $this->completedAt,
            'overdue_since' => $this->overdueSince,
            'penalty_rial' => $this->penaltyRial,
            'netting_batch_id' => $this->nettingBatchId,
            'parent_settlement_id' => $this->parentSettlementId,
        ];
    }
}
