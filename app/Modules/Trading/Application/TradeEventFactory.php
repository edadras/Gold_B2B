<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

use App\Modules\Trading\Events\TradeExecuted;
use App\Modules\Trading\Infrastructure\Models\Trade;

/**
 * One place that turns a Trade row into the TradeExecuted event.
 *
 * All three channels — order book, OTC, RFQ — announce the same way, and the
 * event is consumed by Settlement, Pricing, Accounting and Reputation, so a
 * field that is populated on one path and forgotten on another would be a
 * silent, module-crossing bug. Building it in exactly one function makes that
 * impossible.
 */
final class TradeEventFactory
{
    public static function executed(Trade $trade): TradeExecuted
    {
        return new TradeExecuted(
            tradeId: $trade->id,
            tradeCode: $trade->trade_code,
            instrumentId: $trade->instrument_id,
            tradeSource: $trade->trade_source->value,
            buyerOrganizationId: $trade->buyer_organization_id,
            sellerOrganizationId: $trade->seller_organization_id,
            buyOrderId: $trade->buy_order_id,
            sellOrderId: $trade->sell_order_id,
            makerSide: $trade->maker_side?->value,
            fineWeightMg: $trade->quantity_fine_mg,
            pricePerGramRial: $trade->price_per_gram_rial,
            grossAmountRial: $trade->gross_amount_rial,
            buyerFeeRial: $trade->buyer_fee_rial,
            sellerFeeRial: $trade->seller_fee_rial,
            taxRial: $trade->tax_rial,
            buyerNetRial: $trade->buyer_net_rial,
            sellerNetRial: $trade->seller_net_rial,
            settlementType: $trade->settlement_type->value,
            deliveryType: $trade->delivery_type->value,
            settlementDeadline: $trade->settlement_deadline->toIso8601String(),
            executedAt: $trade->executed_at->toIso8601String(),
        );
    }
}
