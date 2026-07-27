<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests\Doubles;

/**
 * The book-change event Trading does not emit yet.
 *
 * BroadcastOrderBookDepth is registered against
 * `App\Modules\Trading\Events\OrderBookChanged` so that depth starts
 * flowing the day that class appears. This double is what proves the listener
 * is ready for it — otherwise that registration would be untested wiring.
 */
final readonly class OrderBookChanged
{
    public function __construct(
        public int $instrumentId,
        public array $bids,
        public array $asks,
        public string $occurredAt = '',
    ) {}
}
