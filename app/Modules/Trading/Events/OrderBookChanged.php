<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/**
 * The visible depth of one instrument changed.
 *
 * Distinct from OrderPlaced / OrderCancelled / OrderFilled, which all report an
 * *order*. This reports the *book* — the aggregated ladder as an outsider is
 * allowed to see it (docs/03-domain/04-trading.md §4.9): at most ten levels a
 * side, price and total quantity and an order count, never an owner and never
 * an individual order's size.
 *
 * It exists because the ladder is what a market data consumer wants and no
 * order-level event carries one. Broadcasting subscribes to this class by name
 * to publish `depth.updated`; anything else that needs to react to the shape of
 * the book rather than to a single order can do the same without asking Trading
 * for a read.
 *
 * Fired after the mutating transaction commits, so the ladder is what a fresh
 * reader would see — never a mid-transaction state that never existed publicly.
 */
final readonly class OrderBookChanged
{
    /**
     * @param  list<array{0: int, 1: int, 2: int}>  $bids  [price_rial, quantity_mg, order_count], best first
     * @param  list<array{0: int, 1: int, 2: int}>  $asks  [price_rial, quantity_mg, order_count], best first
     */
    public function __construct(
        public int $instrumentId,
        public string $instrumentCode,
        public array $bids,
        public array $asks,
        public string $occurredAt,
    ) {}
}
