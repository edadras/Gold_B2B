<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/** A GTD order passed its expiry, or a DAY order outlived its session. */
final readonly class OrderExpired
{
    public function __construct(
        public int $orderId,
        public int $organizationId,
        public int $instrumentId,
        public int $filledMg,
        public int $expiredMg,
        public int $releasedAmount,
        public string $occurredAt,
    ) {}
}
