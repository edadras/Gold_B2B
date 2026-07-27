<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/**
 * A new order was accepted and reserved. Fired AFTER the placement transaction
 * commits (AGENT_BRIEF rule 3), so a listener that reads the database sees the
 * order and any trades it caused.
 */
final readonly class OrderPlaced
{
    public function __construct(
        public int $orderId,
        public string $orderCode,
        public int $instrumentId,
        public int $organizationId,
        public int $userId,
        public string $side,
        public string $orderType,
        public string $timeInForce,
        public int $quantityMg,
        public ?int $priceRial,
        public string $status,
        public string $occurredAt,
    ) {}
}
