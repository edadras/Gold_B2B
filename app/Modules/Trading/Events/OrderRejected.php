<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/**
 * The order never reached the book: risk refused it, the market was closed, the
 * balance was short, or the instrument parameters were violated.
 *
 * $orderId is null when the rejection happened before any row was written,
 * which is the normal case — validation runs outside the transaction.
 */
final readonly class OrderRejected
{
    public function __construct(
        public ?int $orderId,
        public int $organizationId,
        public int $instrumentId,
        public string $side,
        public int $quantityMg,
        public ?int $priceRial,
        public string $reasonCode,
        public string $reason,
        public string $occurredAt,
    ) {}
}
