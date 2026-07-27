<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Contracts\PriceReaderInterface;
use App\Modules\Pricing\Domain\CircuitBreakerDecision;
use App\Modules\Pricing\Events\CircuitBreakerTriggered;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * F24 — deviation_bps = floor(|current − reference| × 10000 / reference).
 *
 * Reference vector: reference 78,000,000, current 80,500,000, threshold 300
 * → 320 bps → halt.
 *
 * This class only decides. Halting the market is the MarketSession state
 * machine's job in the Trading module; we emit CircuitBreakerTriggered and let
 * it act.
 */
final class CircuitBreakerEvaluator
{
    public function __construct(
        private readonly PriceReaderInterface $prices,
        private readonly Dispatcher $events,
    ) {}

    public function evaluate(
        int $instrumentId,
        PricePerFineGram $current,
        ?PricePerFineGram $reference = null,
        ?int $thresholdBps = null,
    ): CircuitBreakerDecision {
        $reference ??= $this->prices->referencePrice($instrumentId);

        if ($reference === null || $reference->rial === 0) {
            // Nothing to compare against; a missing reference is handled by
            // NoPriceAvailable, not by the breaker.
            return CircuitBreakerDecision::notEvaluated($instrumentId);
        }

        $threshold = $thresholdBps ?? (int) config('goldb2b.pricing.circuit_breaker_bps', 300);
        $deviation = $current->deviationBpsFrom($reference);
        $shouldHalt = $deviation > $threshold;

        $decision = new CircuitBreakerDecision(
            instrumentId: $instrumentId,
            referenceRial: $reference->rial,
            currentRial: $current->rial,
            deviationBps: $deviation,
            thresholdBps: $threshold,
            shouldHalt: $shouldHalt,
        );

        if ($shouldHalt) {
            $this->events->dispatch(new CircuitBreakerTriggered(
                instrumentId: $instrumentId,
                referenceRial: $reference->rial,
                currentRial: $current->rial,
                deviationBps: $deviation,
                thresholdBps: $threshold,
            ));
        }

        return $decision;
    }
}
