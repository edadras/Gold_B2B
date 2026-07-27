<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/**
 * A trade was written. The most widely consumed event in the platform:
 * Settlement builds the obligation from it, Pricing folds it into the day's
 * statistics, Accounting posts the vouchers, Reputation counts it.
 *
 * Scalars only — no Eloquent model may cross this boundary (AGENT_BRIEF rule 7).
 *
 * `grossAmount` duplicates `grossAmountRial` on purpose. The implementation
 * guide (§2.2) names the property `grossAmountRial`; Accounting's defensive
 * reader, written before this module existed, looks for `grossAmount`. Carrying
 * both costs one int and lets each side keep the name it already expects.
 */
final readonly class TradeExecuted
{
    public int $grossAmount;

    public function __construct(
        public int $tradeId,
        public string $tradeCode,
        public int $instrumentId,
        public string $tradeSource,
        public int $buyerOrganizationId,
        public int $sellerOrganizationId,
        public ?int $buyOrderId,
        public ?int $sellOrderId,
        public ?string $makerSide,
        public int $fineWeightMg,
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
        public string $executedAt,
    ) {
        $this->grossAmount = $grossAmountRial;
    }
}
