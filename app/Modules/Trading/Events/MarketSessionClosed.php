<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/**
 * The session ended. DAY orders were cancelled, GTC orders were left resting,
 * and the closing statistics are final (§4.8).
 */
final readonly class MarketSessionClosed
{
    public function __construct(
        public int $sessionId,
        public int $instrumentId,
        public string $sessionDate,
        public ?int $closingPriceRial,
        public int $volumeMg,
        public int $tradeCount,
        public int $cancelledDayOrders,
        public string $occurredAt,
    ) {}
}
