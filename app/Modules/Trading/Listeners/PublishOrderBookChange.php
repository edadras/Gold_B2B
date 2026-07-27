<?php

declare(strict_types=1);

namespace App\Modules\Trading\Listeners;

use App\Modules\Trading\Application\InstrumentRepository;
use App\Modules\Trading\Application\OrderBookReader;
use App\Modules\Trading\Contracts\OrderBookLevel;
use App\Modules\Trading\Events\OrderBookChanged;

/**
 * Turns order-level events into one book-level event.
 *
 * Every order event that can change the visible ladder — placed, cancelled,
 * expired, filled, partially filled, auction cleared — lands here. The listener
 * re-reads the aggregated depth and emits OrderBookChanged.
 *
 * Reading rather than reconstructing is deliberate. A placement that crosses
 * fires OrderPlaced *and* a fill event for each side, and reconstructing the
 * ladder from those deltas would mean reimplementing aggregation in a listener
 * and getting a different answer than the depth endpoint the moment the two
 * drift. One grouped, indexed query per side against the same reader the API
 * uses cannot disagree with itself.
 *
 * That same crossing placement lands here three times, so the last published
 * ladder per instrument is remembered and an unchanged book publishes nothing.
 * The listener is a singleton for that memory to survive the request; it is a
 * per-request cache, not a cross-request one, and it is only ever an
 * optimisation — a stale entry can suppress at most a duplicate frame, never a
 * real change, because the comparison is against the ladder itself.
 *
 * Fired outside any transaction: the producers dispatch after commit
 * (AGENT_BRIEF rule 3), so the read sees committed state.
 */
final class PublishOrderBookChange
{
    /** @var array<int, string> instrument id => signature of the last published ladder */
    private array $published = [];

    public function __construct(
        private readonly OrderBookReader $book,
        private readonly InstrumentRepository $instruments,
    ) {}

    public function handle(object $event): void
    {
        $instrumentId = $event->instrumentId ?? null;

        if (! is_int($instrumentId)) {
            return;
        }

        $instrument = $this->instruments->find($instrumentId);

        if ($instrument === null) {
            return;
        }

        $depth = $this->book->depth($instrument);

        $bids = $this->ladder($depth->bids);
        $asks = $this->ladder($depth->asks);

        $signature = json_encode([$bids, $asks], JSON_THROW_ON_ERROR);

        if (($this->published[$instrumentId] ?? null) === $signature) {
            return;
        }

        $this->published[$instrumentId] = $signature;

        event(new OrderBookChanged(
            instrumentId: $instrumentId,
            instrumentCode: $instrument->code,
            bids: $bids,
            asks: $asks,
            occurredAt: $depth->timestamp,
        ));
    }

    /**
     * @param  list<OrderBookLevel>  $levels
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private function ladder(array $levels): array
    {
        return array_map(
            static fn (OrderBookLevel $level): array => [
                $level->priceRial,
                $level->quantityMg,
                $level->orderCount,
            ],
            $levels,
        );
    }
}
