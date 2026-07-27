<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/** The order is completely executed and has left the book. */
final readonly class OrderFilled
{
    public function __construct(
        public int $orderId,
        public int $organizationId,
        public int $instrumentId,
        public string $side,
        public int $quantityMg,
        public int $averagePriceRial,
        public string $occurredAt,
    ) {}
}
