<?php

declare(strict_types=1);

namespace App\Modules\Trading\Listeners;

use App\Modules\Pricing\Events\CircuitBreakerTriggered;
use App\Modules\Trading\Application\MarketSessionService;
use Illuminate\Support\Facades\Log;

/**
 * F24 breached — pause the instrument (§4.8).
 *
 * Pricing signals and Trading decides: "Pricing signals, it does not halt"
 * (NoPriceAvailable's own docblock). This listener is where that division of
 * labour is honoured, and it is why the circuit breaker lives in Pricing while
 * the session state machine lives here.
 *
 * Typed against the concrete event because Pricing\Events is part of that
 * module's public surface, which Trading is allowed to import.
 */
final readonly class PauseMarketOnCircuitBreaker
{
    public function __construct(private MarketSessionService $sessions) {}

    public function handle(CircuitBreakerTriggered $event): void
    {
        $session = $this->sessions->pauseForCircuitBreaker(
            $event->instrumentId,
            $event->deviationBps,
            $event->thresholdBps,
        );

        if ($session === null) {
            Log::info('Circuit breaker fired for an instrument with no open session', [
                'instrument_id' => $event->instrumentId,
                'deviation_bps' => $event->deviationBps,
            ]);
        }
    }
}
