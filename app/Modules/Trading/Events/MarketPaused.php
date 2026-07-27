<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/**
 * Trading is halted for an instrument: circuit breaker, missing reference
 * price, or a manual halt. Cancellation stays available, new orders do not
 * (§4.8).
 */
final readonly class MarketPaused
{
    public const REASON_CIRCUIT_BREAKER = 'CIRCUIT_BREAKER';

    public const REASON_NO_PRICE = 'NO_PRICE_AVAILABLE';

    public const REASON_MANUAL = 'MANUAL_HALT';

    public function __construct(
        public int $sessionId,
        public int $instrumentId,
        public string $reasonCode,
        public string $reason,
        public ?string $resumeAt,
        public string $occurredAt,
    ) {}
}
