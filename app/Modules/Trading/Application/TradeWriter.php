<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

use App\Modules\Shared\Calculation\TradeValueCalculator;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Trading\Domain\DeliveryType;
use App\Modules\Trading\Domain\Exceptions\SelfTradeException;
use App\Modules\Trading\Domain\TradeSource;
use App\Modules\Trading\Domain\TradeStatus;
use App\Modules\Trading\Infrastructure\Models\Instrument;
use App\Modules\Trading\Infrastructure\Models\Trade;

/**
 * Writes the trade rows that do not come from the order book: OTC and RFQ.
 *
 * The book has its own path inside MatchingEngine because a book trade also has
 * to touch two order rows, two fills and two reservations. What is shared is
 * the pricing — every channel prices through TradeValueCalculator, which is the
 * whole point of that class (architecture principle 3).
 *
 * Fee roles off the book: the party whose terms were accepted is the maker and
 * pays the maker rate; the party who accepted is the taker. It is the same
 * economics as §4.4 — whoever put a price on the table provided the liquidity.
 */
final readonly class TradeWriter
{
    public function __construct(
        private TradeValueCalculator $calculator,
        private FeeSchedule $fees,
        private SettlementDeadlineCalculator $deadlines,
    ) {}

    /**
     * @param  array<string, int|null>  $links  e.g. ['otc_offer_id' => 12]
     */
    public function write(
        Instrument $instrument,
        TradeSource $source,
        int $buyerOrganizationId,
        int $sellerOrganizationId,
        FineWeight $quantity,
        PricePerFineGram $price,
        bool $buyerIsMaker,
        array $links = [],
        ?DeliveryType $deliveryType = null,
    ): Trade {
        if ($buyerOrganizationId === $sellerOrganizationId) {
            throw new SelfTradeException($buyerOrganizationId);
        }

        $valuation = $this->calculator->value(
            fineWeight: $quantity,
            price: $price,
            buyerFee: $this->fees->termsFor($buyerIsMaker),
            sellerFee: $this->fees->termsFor(! $buyerIsMaker),
            tax: $this->fees->taxTerms(),
        );

        $settlementType = $instrument->settlement_type;

        $trade = new Trade(array_merge([
            'trade_code' => 'TRD-PENDING',
            'instrument_id' => $instrument->id,
            'trade_source' => $source,
            'buyer_organization_id' => $buyerOrganizationId,
            'seller_organization_id' => $sellerOrganizationId,
            // No maker_side off the book: there is no resting order, so the
            // column stays NULL exactly as §2.4 allows.
            'maker_side' => null,
            'quantity_fine_mg' => $quantity->milligrams,
            'price_per_gram_rial' => $price->rial,
            'gross_amount_rial' => $valuation->grossAmount->amount,
            'buyer_fee_rial' => $valuation->buyerFee->amount,
            'seller_fee_rial' => $valuation->sellerFee->amount,
            'tax_rial' => $valuation->totalTax()->amount,
            'buyer_net_rial' => $valuation->buyerNet->amount,
            'seller_net_rial' => $valuation->sellerNet->amount,
            'settlement_type' => $settlementType,
            'delivery_type' => $deliveryType ?? DeliveryType::CUSTODY_CHANGE,
            'settlement_deadline' => $this->deadlines->deadlineFor($settlementType),
            'status' => TradeStatus::EXECUTED,
            'executed_at' => now(),
        ], $links));

        $trade->save();

        $trade->trade_code = CodeGenerator::trade($trade->id);
        $trade->save();

        return $trade;
    }
}
