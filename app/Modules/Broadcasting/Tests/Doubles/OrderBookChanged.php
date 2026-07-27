<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests\Doubles;

/**
 * A stand-in for `App\Modules\Trading\Events\OrderBookChanged`.
 *
 * Trading does emit that event now, and BroadcastOrderBookDepth is registered
 * against it by name. The double stays because Broadcasting sits below Trading
 * in the dependency graph and may not import it: these tests must keep proving
 * the listener handles the *shape* without binding this module to the class.
 *
 * The shape is pinned from the other side too —
 * App\Modules\Trading\Tests\Feature\OrderBookChangedTest asserts the real
 * event's fields — so a rename in Trading fails there rather than silently
 * turning depth off here.
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
