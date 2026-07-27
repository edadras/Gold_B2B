<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Resources;

use App\Modules\Pricing\Application\QuoteSnapshot;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * A live quote for one instrument (§2.4).
 *
 * `last_is_stale` is deliberately part of the contract rather than something
 * the client infers from a timestamp: staleness is a Pricing decision driven by
 * `goldb2b.pricing.max_staleness_seconds`, and a client guessing its own
 * threshold would show a green price the platform considers unusable.
 */
final class QuoteResource extends ApiResource
{
    public function __construct(mixed $resource, private readonly ?string $instrumentCode = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var QuoteSnapshot $quote */
        $quote = $this->resource;

        return [
            'instrument' => $this->instrumentCode,
            'instrument_id' => $quote->instrumentId,
            'best_bid_rial' => $quote->bestBid,
            'best_bid_quantity_mg' => $quote->bestBidQtyMg,
            'best_ask_rial' => $quote->bestAsk,
            'best_ask_quantity_mg' => $quote->bestAskQtyMg,
            'last_price_rial' => $quote->lastPrice,
            'last_quantity_mg' => $quote->lastQtyMg,
            'last_at' => Display::iso($quote->lastAt),
            'last_is_stale' => $quote->lastIsStale,
            'mid_price_rial' => $quote->mid(),
            'spread_rial' => $quote->spread?->rial,
            'spread_bps' => $quote->spread?->bps,
            'change_rial' => $quote->changeRial(),
            'change_bps' => $quote->changeBps(),
            'day_open_rial' => $quote->dayOpen,
            'day_high_rial' => $quote->dayHigh,
            'day_low_rial' => $quote->dayLow,
            'day_volume_mg' => $quote->dayVolumeMg,
            'day_vwap_rial' => $quote->dayVwap,
            'day_trade_count' => $quote->dayTradeCount,
        ] + $this->display($request, [
            'last_price_display' => Display::rial($quote->lastPrice),
            'best_bid_display' => Display::rial($quote->bestBid),
            'best_ask_display' => Display::rial($quote->bestAsk),
            'day_volume_display' => Display::grams($quote->dayVolumeMg),
            'change_display' => Display::bps($quote->changeBps()),
            'last_at_jalali' => Display::jalali($quote->lastAt),
        ]);
    }
}
