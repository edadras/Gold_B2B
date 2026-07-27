<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/**
 * The unfilled remainder was withdrawn — by the member, by IOC, by the session
 * close, or by a suspension of the organisation.
 */
final readonly class OrderCancelled
{
    public function __construct(
        public int $orderId,
        public int $organizationId,
        public int $instrumentId,
        public string $side,
        public int $filledMg,
        public int $cancelledMg,
        public int $releasedAmount,
        public string $reason,
        public string $occurredAt,
    ) {}
}
