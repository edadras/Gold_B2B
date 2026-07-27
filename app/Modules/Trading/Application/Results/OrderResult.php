<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application\Results;

use App\Modules\Trading\Infrastructure\Models\Order;
use App\Modules\Trading\Infrastructure\Models\Trade;

/**
 * What placing an order produced: the order itself and every trade it caused.
 *
 * Stays inside the module — it carries Eloquent models, so it is a service
 * return type, not a contract.
 */
final readonly class OrderResult
{
    /** @param list<Trade> $trades */
    public function __construct(
        public Order $order,
        public array $trades = [],
    ) {}

    public function executed(): bool
    {
        return $this->trades !== [];
    }

    public function filledMg(): int
    {
        return $this->order->filled_mg;
    }
}
