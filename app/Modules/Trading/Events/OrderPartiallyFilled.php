<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/** Some of the order executed; the remainder is still resting in the book. */
final readonly class OrderPartiallyFilled
{
    public function __construct(
        public int $orderId,
        public int $organizationId,
        public int $instrumentId,
        public string $side,
        public int $filledMg,
        public int $remainingMg,
        public int $lastFillPriceRial,
        public string $occurredAt,
    ) {}
}
