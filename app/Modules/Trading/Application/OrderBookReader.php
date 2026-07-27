<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Trading\Contracts\OrderBookLevel;
use App\Modules\Trading\Contracts\OrderBookSnapshot;
use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Infrastructure\Models\Instrument;
use Illuminate\Support\Facades\DB;

/**
 * The public depth view (docs/03-domain/04-trading.md §4.9).
 *
 * Three disclosure rules, all enforced here rather than in a presenter, so no
 * future caller can accidentally leak what this class never selects:
 *
 *   · the identity of an order's owner is NEVER exposed;
 *   · order_count is shown, but not the size of any individual order;
 *   · at most ten price levels per side.
 *
 * Aggregation happens in SQL — one grouped query per side — because the depth
 * endpoint is the hottest read on the platform and pulling every resting order
 * into PHP to sum it would be an N+1 in disguise.
 */
final class OrderBookReader
{
    /** "عمق نمایش‌داده‌شده به ۱۰ سطح محدود است" — §4.9. */
    public const MAX_LEVELS = 10;

    public function depth(Instrument $instrument, int $levels = self::MAX_LEVELS): OrderBookSnapshot
    {
        $levels = max(1, min($levels, self::MAX_LEVELS));

        return new OrderBookSnapshot(
            instrumentCode: $instrument->code,
            bids: $this->levels($instrument->id, Side::BUY, $levels),
            asks: $this->levels($instrument->id, Side::SELL, $levels),
            timestamp: now()->toIso8601ZuluString('millisecond'),
        );
    }

    public function bestBid(int $instrumentId): ?PricePerFineGram
    {
        return $this->bestPrice($instrumentId, Side::BUY);
    }

    public function bestAsk(int $instrumentId): ?PricePerFineGram
    {
        return $this->bestPrice($instrumentId, Side::SELL);
    }

    /** Total unfilled quantity resting at a given side's best price. */
    public function quantityAtBest(int $instrumentId, Side $side): ?int
    {
        $levels = $this->levels($instrumentId, $side, 1);

        return $levels === [] ? null : $levels[0]->quantityMg;
    }

    /**
     * @return list<OrderBookLevel>
     */
    private function levels(int $instrumentId, Side $side, int $limit): array
    {
        $rows = DB::table('orders')
            ->selectRaw('price_rial, SUM(quantity_mg - filled_mg) AS quantity_mg, COUNT(*) AS order_count')
            ->where('instrument_id', $instrumentId)
            ->where('side', $side->value)
            ->whereIn('status', OrderStatus::matchable())
            ->whereNotNull('price_rial')
            ->whereColumn('quantity_mg', '>', 'filled_mg')
            ->groupBy('price_rial')
            ->orderBy('price_rial', $side->isBuy() ? 'desc' : 'asc')
            ->limit($limit)
            ->get();

        $levels = [];

        foreach ($rows as $row) {
            $levels[] = new OrderBookLevel(
                priceRial: (int) $row->price_rial,
                quantityMg: (int) $row->quantity_mg,
                orderCount: (int) $row->order_count,
            );
        }

        return $levels;
    }

    private function bestPrice(int $instrumentId, Side $side): ?PricePerFineGram
    {
        $levels = $this->levels($instrumentId, $side, 1);

        return $levels === [] ? null : PricePerFineGram::fromRial($levels[0]->priceRial);
    }
}
