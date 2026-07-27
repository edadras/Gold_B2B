<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Contracts;

/**
 * Everything Settlement needs to know about a trade, as scalars.
 *
 * Trading is built by another agent and its Contracts namespace may not exist
 * yet, so Settlement declares its own read model rather than importing one.
 * When Trading publishes a snapshot of its own, the adapter behind
 * TradeReaderInterface is the only thing that has to change.
 *
 * Fee fields follow F8/F9 of docs/11-appendix/01-formulas.md: the buyer's fee
 * is added to what they owe, the seller's is deducted from what they receive.
 */
final readonly class TradeSnapshot
{
    public function __construct(
        public int $tradeId,
        public int $buyerOrganizationId,
        public int $sellerOrganizationId,
        public int $quantityFineMg,
        public int $grossAmountRial,
        public int $buyerFeeRial = 0,
        public int $sellerFeeRial = 0,
        public string $settlementType = 'T0',
        public ?string $settlementDeadline = null,
        public ?int $pricePerGramRial = null,
    ) {}

    /** F9 — what the buyer actually parts with, and therefore what is locked. */
    public function buyerNetRial(): int
    {
        return $this->grossAmountRial + $this->buyerFeeRial;
    }

    /** F9 — what the seller actually receives. */
    public function sellerNetRial(): int
    {
        return $this->grossAmountRial - $this->sellerFeeRial;
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'trade_id' => $this->tradeId,
            'buyer_organization_id' => $this->buyerOrganizationId,
            'seller_organization_id' => $this->sellerOrganizationId,
            'quantity_fine_mg' => $this->quantityFineMg,
            'gross_amount_rial' => $this->grossAmountRial,
            'buyer_fee_rial' => $this->buyerFeeRial,
            'seller_fee_rial' => $this->sellerFeeRial,
            'settlement_type' => $this->settlementType,
            'settlement_deadline' => $this->settlementDeadline,
            'price_per_gram_rial' => $this->pricePerGramRial,
        ];
    }
}
