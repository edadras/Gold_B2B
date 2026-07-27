<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Listeners;

use App\Modules\Broadcasting\Application\MarketBroadcaster;
use App\Modules\Broadcasting\Domain\EventShape;

/**
 * The depth seam.
 *
 * `depth.updated` needs the ladder, and no Trading domain event carries one
 * today — OrderPlaced and friends report the order, not the book. Broadcasting
 * may not import Trading to go and read it (dependency graph), and Trading is
 * not this module's to change. So there are two ways in and this listener is
 * the passive one:
 *
 *   · MarketBroadcaster::depth() — a plain public method, for the matching
 *     engine or a book-publisher command that already holds the ladder;
 *   · this listener, registered against
 *     `App\Modules\Trading\Events\OrderBookChanged`, which does not exist yet.
 *     If Trading ever emits a book-change event carrying `bids`/`asks`, depth
 *     starts flowing with no change here — and until then this costs one
 *     never-fired listener registration.
 *
 * The throttle (10/sec, 100ms aggregation) lives in MarketBroadcaster, so both
 * routes are rate-limited identically.
 */
final class BroadcastOrderBookDepth
{
    public function __construct(private readonly MarketBroadcaster $market) {}

    public function handle(object $event): void
    {
        $shape = EventShape::of($event);

        $instrumentId = $shape->int('instrumentId');
        $bids = $shape->list('bids');
        $asks = $shape->list('asks');

        if ($instrumentId === null || $bids === null || $asks === null) {
            return;
        }

        // DepthUpdated narrows each level to three ints; anything else the
        // producer attached is discarded before it can reach a public channel.
        $this->market->depth(
            instrumentId: $instrumentId,
            bids: $bids,
            asks: $asks,
            timestamp: $shape->string('occurredAt'),
        );
    }
}
