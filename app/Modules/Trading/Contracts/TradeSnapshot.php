<?php

declare(strict_types=1);

namespace App\Modules\Trading\Contracts;

/**
 * An executed trade as other modules may see it: scalars only, no model.
 *
 * Settlement builds its obligation from this, and the tape endpoint renders it
 * with the counterparty ids stripped.
 */
final readonly class TradeSnapshot
{
    public function __construct(
        public int $id,
        public string $tradeCode,
        public int $instrumentId,
        public string $tradeSource,
        public int $buyerOrganizationId,
        public int $sellerOrganizationId,
        public int $quantityFineMg,
        public int $pricePerGramRial,
        public int $grossAmountRial,
        public int $buyerFeeRial,
        public int $sellerFeeRial,
        public int $taxRial,
        public int $buyerNetRial,
        public int $sellerNetRial,
        public string $settlementType,
        public string $deliveryType,
        public string $settlementDeadline,
        public string $status,
        public string $executedAt,
    ) {}
}
