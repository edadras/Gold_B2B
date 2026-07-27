<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Contracts\TradePrint;
use App\Modules\Pricing\Contracts\TradePrintSourceInterface;
use App\Modules\Pricing\Domain\CandleInterval;
use App\Modules\Pricing\Infrastructure\Models\PriceCandle;
use App\Modules\Shared\Support\IntMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * OHLCV construction, docs/03-domain/07-pricing.md §7.7.
 *
 * Only ORDER_BOOK prints build candles, and an empty bucket produces no row —
 * a gap in the chart is honest, a flat synthetic candle is not.
 */
final class CandleService
{
    public function __construct(private readonly TradePrintSourceInterface $prints) {}

    /** @return int number of candles written */
    public function buildAll(?CarbonImmutable $at = null): int
    {
        $at ??= CarbonImmutable::now();
        $written = 0;

        foreach ($this->prints->activeInstrumentIds() as $instrumentId) {
            foreach (CandleInterval::all() as $interval) {
                if ($this->build($instrumentId, $interval, $at) !== null) {
                    $written++;
                }
            }
        }

        return $written;
    }

    public function build(int $instrumentId, CandleInterval $interval, ?CarbonImmutable $at = null): ?PriceCandle
    {
        $at ??= CarbonImmutable::now();

        $start = $interval->bucketStart($at);
        $end = $start->addSeconds($interval->seconds());

        $prints = array_values(array_filter(
            $this->prints->printsBetween($instrumentId, $start, $end),
            static fn (TradePrint $print): bool => $print->source->buildsCandles(),
        ));

        if ($prints === []) {
            return null;
        }

        usort(
            $prints,
            static fn (TradePrint $a, TradePrint $b): int => $a->executedAt->getTimestamp() <=> $b->executedAt->getTimestamp(),
        );

        $priceValues = array_map(static fn (TradePrint $p): int => $p->pricePerFineGramRial, $prints);
        $volume = IntMath::sum(array_map(static fn (TradePrint $p): int => $p->fineWeightMg, $prints));

        return DB::transaction(fn (): PriceCandle => PriceCandle::query()->updateOrCreate(
            [
                'instrument_id' => $instrumentId,
                'interval_code' => $interval->value,
                'opened_at' => $start,
            ],
            [
                'open_price' => $prints[0]->pricePerFineGramRial,
                'close_price' => $prints[count($prints) - 1]->pricePerFineGramRial,
                'high_price' => max($priceValues),
                'low_price' => min($priceValues),
                'volume_mg' => $volume,
                'trade_count' => count($prints),
            ],
        ));
    }

    /**
     * @return list<PriceCandle>
     */
    public function history(int $instrumentId, CandleInterval $interval, int $limit = 100): array
    {
        return PriceCandle::query()
            ->where('instrument_id', $instrumentId)
            ->where('interval_code', $interval->value)
            ->orderByDesc('opened_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->all();
    }
}
