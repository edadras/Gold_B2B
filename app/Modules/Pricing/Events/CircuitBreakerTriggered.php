<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Events;

/** F24 breached. The MarketSession state machine decides what to do with it. */
final readonly class CircuitBreakerTriggered
{
    public function __construct(
        public int $instrumentId,
        public int $referenceRial,
        public int $currentRial,
        public int $deviationBps,
        public int $thresholdBps,
    ) {}
}
