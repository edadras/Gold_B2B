<?php

declare(strict_types=1);

namespace App\Modules\Trading\Infrastructure\Readers;

use App\Modules\Pricing\Contracts\TradePrint;
use App\Modules\Pricing\Contracts\TradePrintSourceInterface;
use App\Modules\Pricing\Contracts\TradeSource as PricingTradeSource;
use App\Modules\Trading\Domain\InstrumentStatus;
use App\Modules\Trading\Infrastructure\Models\Instrument;
use App\Modules\Trading\Infrastructure\Models\Trade;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Replaces Pricing's NullTradePrintSource now that Trading owns the trade
 * table: CandleService replays prints through this to build OHLC bars.
 *
 * Every print is handed over, OTC and RFQ included. Pricing — not Trading —
 * decides what each source may influence: TradeSource::buildsCandles() lets
 * only ORDER_BOOK prints shape a candle, while VWAP and volume count
 * everything (§7.6). Filtering here would quietly change that policy.
 *
 * The half-open [$from, $to) window matches the interface contract, so adjacent
 * candles cannot both claim a trade on the boundary.
 */
final class EloquentTradePrintSource implements TradePrintSourceInterface
{
    /** @return list<TradePrint> */
    public function printsBetween(int $instrumentId, CarbonInterface $from, CarbonInterface $to): array
    {
        $trades = Trade::query()
            ->where('instrument_id', $instrumentId)
            ->where('executed_at', '>=', CarbonImmutable::instance($from))
            ->where('executed_at', '<', CarbonImmutable::instance($to))
            ->orderBy('executed_at')
            ->orderBy('id')
            ->get(['id', 'instrument_id', 'price_per_gram_rial', 'quantity_fine_mg', 'executed_at', 'trade_source']);

        $prints = [];

        foreach ($trades as $trade) {
            $prints[] = new TradePrint(
                instrumentId: $trade->instrument_id,
                pricePerFineGramRial: $trade->price_per_gram_rial,
                fineWeightMg: $trade->quantity_fine_mg,
                executedAt: CarbonImmutable::instance($trade->executed_at),
                source: PricingTradeSource::from($trade->trade_source->value),
                tradeId: $trade->id,
            );
        }

        return $prints;
    }

    /** @return list<int> */
    public function activeInstrumentIds(): array
    {
        return Instrument::query()
            ->where('status', InstrumentStatus::ACTIVE->value)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
