<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Contracts\QuoteWriterInterface;
use App\Modules\Pricing\Contracts\TradePrint;
use App\Modules\Pricing\Domain\Spread;
use App\Modules\Pricing\Domain\VwapCalculator;
use App\Modules\Pricing\Infrastructure\Models\MarketQuote;
use App\Modules\Shared\Support\IntMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Owner of the market_quotes table.
 *
 * Rules taken from docs/03-domain/07-pricing.md §7.6:
 *   · LAST moves on ORDER_BOOK prints only — a fabricated OTC print must not be
 *     able to steer the public price.
 *   · VWAP, volume, open, high and low include every print, OTC included.
 *   · When the order book saw no trade today, LAST stays at yesterday's value
 *     and is marked stale.
 */
final class QuoteService implements QuoteWriterInterface
{
    public function __construct(
        private readonly VwapCalculator $vwap,
        private readonly PriceAlertService $alerts,
    ) {}

    public function recordTrade(TradePrint $print): void
    {
        if ($print->pricePerFineGramRial <= 0) {
            throw new InvalidArgumentException('Trade price must be positive');
        }

        if ($print->fineWeightMg <= 0) {
            throw new InvalidArgumentException('Trade quantity must be positive');
        }

        $lastPrice = DB::transaction(function () use ($print): ?int {
            $quote = $this->lockedQuote($print->instrumentId);

            $attributes = $this->rolledOver($quote, $print->executedAt);

            $attributes['day_volume_mg'] = IntMath::add($attributes['day_volume_mg'], $print->fineWeightMg);
            $attributes['day_vwap_numerator'] = IntMath::add(
                $attributes['day_vwap_numerator'],
                $this->vwap->contribution($print->pricePerFineGramRial, $print->fineWeightMg),
            );
            $attributes['day_trade_count'] = $attributes['day_trade_count'] + 1;
            $attributes['day_vwap'] = $this->vwap->fromAccumulators(
                $attributes['day_vwap_numerator'],
                $attributes['day_volume_mg'],
            );

            $attributes['day_open'] ??= $print->pricePerFineGramRial;
            $attributes['day_high'] = $attributes['day_high'] === null
                ? $print->pricePerFineGramRial
                : max($attributes['day_high'], $print->pricePerFineGramRial);
            $attributes['day_low'] = $attributes['day_low'] === null
                ? $print->pricePerFineGramRial
                : min($attributes['day_low'], $print->pricePerFineGramRial);

            if ($print->source->movesLastPrice()) {
                $attributes['last_price'] = $print->pricePerFineGramRial;
                $attributes['last_qty_mg'] = $print->fineWeightMg;
                $attributes['last_at'] = $print->executedAt;
                $attributes['last_is_stale'] = false;
            }

            $attributes['updated_at'] = CarbonImmutable::now();

            $quote->forceFill($attributes)->save();

            return $attributes['last_price'];
        });

        // Rule 3: alert evaluation dispatches events, so it runs after commit.
        if ($lastPrice !== null) {
            $this->alerts->evaluate($print->instrumentId, $lastPrice);
        }
    }

    public function updateTopOfBook(
        int $instrumentId,
        ?int $bestBidRial,
        ?int $bestBidQtyMg,
        ?int $bestAskRial,
        ?int $bestAskQtyMg,
    ): void {
        DB::transaction(function () use ($instrumentId, $bestBidRial, $bestBidQtyMg, $bestAskRial, $bestAskQtyMg): void {
            $quote = $this->lockedQuote($instrumentId);

            $quote->forceFill([
                'best_bid' => $bestBidRial,
                'best_bid_qty_mg' => $bestBidRial === null ? null : $bestBidQtyMg,
                'best_ask' => $bestAskRial,
                'best_ask_qty_mg' => $bestAskRial === null ? null : $bestAskQtyMg,
                'updated_at' => CarbonImmutable::now(),
            ])->save();
        });
    }

    public function snapshot(int $instrumentId): ?QuoteSnapshot
    {
        /** @var MarketQuote|null $quote */
        $quote = MarketQuote::query()->find($instrumentId);

        if ($quote === null) {
            return null;
        }

        $spread = $quote->best_bid !== null && $quote->best_ask !== null
            ? Spread::between($quote->best_bid, $quote->best_ask)
            : null;

        return new QuoteSnapshot(
            instrumentId: (int) $quote->instrument_id,
            bestBid: $quote->best_bid,
            bestBidQtyMg: $quote->best_bid_qty_mg,
            bestAsk: $quote->best_ask,
            bestAskQtyMg: $quote->best_ask_qty_mg,
            lastPrice: $quote->last_price,
            lastQtyMg: $quote->last_qty_mg,
            lastAt: $quote->last_at,
            lastIsStale: $this->lastIsStale($quote),
            dayOpen: $quote->day_open,
            dayHigh: $quote->day_high,
            dayLow: $quote->day_low,
            dayVolumeMg: $quote->day_volume_mg,
            dayVwap: $quote->day_vwap,
            dayTradeCount: $quote->day_trade_count,
            spread: $spread,
        );
    }

    /**
     * Session statistics reset on the first print of a new trading day; LAST is
     * deliberately carried over and flagged stale instead (§7.6).
     *
     * @return array<string, mixed>
     */
    private function rolledOver(MarketQuote $quote, CarbonImmutable $executedAt): array
    {
        $timezone = (string) config('goldb2b.market.timezone', 'Asia/Tehran');
        $sessionDate = $executedAt->setTimezone($timezone)->toDateString();

        $current = [
            'day_open' => $quote->day_open,
            'day_high' => $quote->day_high,
            'day_low' => $quote->day_low,
            'day_volume_mg' => $quote->day_volume_mg,
            'day_vwap_numerator' => $quote->day_vwap_numerator,
            'day_trade_count' => $quote->day_trade_count,
            'last_price' => $quote->last_price,
            'last_qty_mg' => $quote->last_qty_mg,
            'last_at' => $quote->last_at,
            'last_is_stale' => $quote->last_is_stale,
            'session_date' => $sessionDate,
        ];

        if ($quote->session_date === $sessionDate) {
            return $current;
        }

        return [
            'day_open' => null,
            'day_high' => null,
            'day_low' => null,
            'day_volume_mg' => 0,
            'day_vwap_numerator' => 0,
            'day_trade_count' => 0,
            'last_price' => $quote->last_price,
            'last_qty_mg' => $quote->last_qty_mg,
            'last_at' => $quote->last_at,
            'last_is_stale' => $quote->last_price !== null,
            'session_date' => $sessionDate,
        ];
    }

    private function lastIsStale(MarketQuote $quote): bool
    {
        if ($quote->last_is_stale) {
            return true;
        }

        if ($quote->last_at === null) {
            return false;
        }

        $timezone = (string) config('goldb2b.market.timezone', 'Asia/Tehran');

        return $quote->last_at->setTimezone($timezone)->toDateString()
            !== CarbonImmutable::now($timezone)->toDateString();
    }

    private function lockedQuote(int $instrumentId): MarketQuote
    {
        /** @var MarketQuote|null $quote */
        $quote = MarketQuote::query()
            ->where('instrument_id', $instrumentId)
            ->lockForUpdate()
            ->first();

        if ($quote !== null) {
            return $quote;
        }

        MarketQuote::query()->insertOrIgnore([
            'instrument_id' => $instrumentId,
            'day_volume_mg' => 0,
            'day_vwap_numerator' => 0,
            'day_trade_count' => 0,
            'last_is_stale' => false,
            'updated_at' => CarbonImmutable::now(),
        ]);

        /** @var MarketQuote $quote */
        $quote = MarketQuote::query()
            ->where('instrument_id', $instrumentId)
            ->lockForUpdate()
            ->firstOrFail();

        return $quote;
    }
}
