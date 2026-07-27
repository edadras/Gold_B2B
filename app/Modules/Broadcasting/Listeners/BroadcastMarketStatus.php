<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Listeners;

use App\Modules\Broadcasting\Application\MarketBroadcaster;
use App\Modules\Broadcasting\Domain\EventShape;
use App\Modules\Broadcasting\Events\MarketStatusChanged;

/**
 * Session and halt transitions → `market.status_changed` on `market.status`.
 *
 * Sources, all read by string name: Trading's MarketSessionOpened /
 * MarketSessionClosed / MarketPaused, and Pricing's CircuitBreakerTriggered
 * and NoPriceAvailable.
 *
 * A circuit breaker is broadcast as PAUSED even though Pricing only *signals*
 * it and Trading decides (§7.4). That is deliberate: the client's job on this
 * channel is to stop showing a tradeable book the instant anything says the
 * market may be halted, and a spurious "paused" that is corrected 200ms later
 * by MarketSessionOpened costs a flicker. The opposite mistake costs an order.
 *
 * NoPriceAvailable is broadcast only when it says the market should halt.
 * A momentary feed gap that Pricing is already smoothing over is not news.
 */
final class BroadcastMarketStatus
{
    public function __construct(private readonly MarketBroadcaster $market) {}

    public function handle(object $event): void
    {
        $shape = EventShape::of($event);

        match ($this->basename($event)) {
            'MarketSessionOpened' => $this->opened($shape),
            'MarketSessionClosed' => $this->closed($shape),
            'MarketPaused' => $this->paused($shape),
            'CircuitBreakerTriggered' => $this->circuitBreaker($shape),
            'NoPriceAvailable' => $this->noPrice($shape),
            default => null,
        };
    }

    private function opened(EventShape $shape): void
    {
        $instrumentId = $shape->int('instrumentId');

        if ($instrumentId === null) {
            return;
        }

        $this->market->status(
            instrumentId: $instrumentId,
            status: MarketStatusChanged::STATUS_OPEN,
            reasonCode: $shape->bool('resumedFromPause') === true ? 'RESUMED' : 'SESSION_OPEN',
            sessionDate: $shape->string('sessionDate'),
            referencePriceRial: $shape->int('openingPriceRial'),
            timestamp: $shape->string('occurredAt'),
        );
    }

    private function closed(EventShape $shape): void
    {
        $instrumentId = $shape->int('instrumentId');

        if ($instrumentId === null) {
            return;
        }

        $this->market->status(
            instrumentId: $instrumentId,
            status: MarketStatusChanged::STATUS_CLOSED,
            reasonCode: 'SESSION_CLOSE',
            sessionDate: $shape->string('sessionDate'),
            referencePriceRial: $shape->int('closingPriceRial'),
            timestamp: $shape->string('occurredAt'),
        );
    }

    private function paused(EventShape $shape): void
    {
        $instrumentId = $shape->int('instrumentId');

        if ($instrumentId === null) {
            return;
        }

        $this->market->status(
            instrumentId: $instrumentId,
            status: MarketStatusChanged::STATUS_PAUSED,
            reasonCode: $shape->string('reasonCode'),
            reason: $shape->string('reason'),
            resumeAt: $shape->string('resumeAt'),
            timestamp: $shape->string('occurredAt'),
        );
    }

    private function circuitBreaker(EventShape $shape): void
    {
        $instrumentId = $shape->int('instrumentId');

        if ($instrumentId === null) {
            return;
        }

        $this->market->status(
            instrumentId: $instrumentId,
            status: MarketStatusChanged::STATUS_PAUSED,
            reasonCode: 'CIRCUIT_BREAKER',
            referencePriceRial: $shape->int('referenceRial'),
        );
    }

    private function noPrice(EventShape $shape): void
    {
        if ($shape->bool('shouldHaltMarket') !== true) {
            return;
        }

        $this->market->status(
            instrumentId: null,
            status: MarketStatusChanged::STATUS_PAUSED,
            reasonCode: 'NO_PRICE_AVAILABLE',
        );
    }

    private function basename(object $event): string
    {
        $class = $event::class;
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
