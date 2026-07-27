<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Events;

/** A member's price alert condition became true. Delivery is a listener's job. */
final readonly class PriceAlertTriggered
{
    public function __construct(
        public int $alertId,
        public int $organizationId,
        public int $userId,
        public int $instrumentId,
        public string $condition,
        public int $threshold,
        public int $observedRial,
    ) {}
}
