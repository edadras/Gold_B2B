<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Contracts\PriceReaderInterface;
use App\Modules\Pricing\Domain\PriceGuardDecision;
use App\Modules\Pricing\Domain\PriceGuardStatus;
use App\Modules\Pricing\Exceptions\PriceOutOfBandException;
use App\Modules\Shared\ValueObjects\PricePerFineGram;

/**
 * Fat-finger protection, docs/03-domain/07-pricing.md §7.8.
 *
 *   |P − reference| / reference ≤ max_order_deviation   → accept
 *   above that                                          → warn, needs an
 *                                                         explicit second
 *                                                         confirmation and an
 *                                                         UNUSUAL_PRICE audit row
 *   above hard_reject_deviation_bps                     → refuse outright
 */
final class OrderPriceGuard
{
    public function __construct(private readonly PriceReaderInterface $prices) {}

    public function check(
        int $instrumentId,
        PricePerFineGram $orderPrice,
        ?PricePerFineGram $reference = null,
    ): PriceGuardDecision {
        $warnAt = (int) config('goldb2b.pricing.max_order_deviation_bps', 1_000);
        $rejectAt = (int) config('goldb2b.pricing.hard_reject_deviation_bps', 2_000);

        $reference ??= $this->referenceFor($instrumentId);

        if ($reference === null || $reference->rial === 0) {
            // With no reference there is nothing to be a fat finger relative to.
            // Refusing every order would be worse than allowing them through;
            // the missing price is surfaced separately by NoPriceAvailable.
            return new PriceGuardDecision(
                status: PriceGuardStatus::ACCEPTED,
                deviationBps: 0,
                referenceRial: 0,
                orderRial: $orderPrice->rial,
                warnThresholdBps: $warnAt,
                rejectThresholdBps: $rejectAt,
            );
        }

        $deviation = $orderPrice->deviationBpsFrom($reference);

        $status = match (true) {
            $deviation > $rejectAt => PriceGuardStatus::REJECTED,
            $deviation > $warnAt => PriceGuardStatus::WARN,
            default => PriceGuardStatus::ACCEPTED,
        };

        return new PriceGuardDecision(
            status: $status,
            deviationBps: $deviation,
            referenceRial: $reference->rial,
            orderRial: $orderPrice->rial,
            warnThresholdBps: $warnAt,
            rejectThresholdBps: $rejectAt,
        );
    }

    /**
     * @throws PriceOutOfBandException when the deviation is past hard rejection
     */
    public function assert(
        int $instrumentId,
        PricePerFineGram $orderPrice,
        ?PricePerFineGram $reference = null,
    ): PriceGuardDecision {
        $decision = $this->check($instrumentId, $orderPrice, $reference);

        if ($decision->isRejected()) {
            throw new PriceOutOfBandException(
                $decision->deviationBps,
                $decision->rejectThresholdBps,
                $decision->referenceRial,
                $decision->orderRial,
            );
        }

        return $decision;
    }

    /**
     * The market's own last price is the fairer yardstick when the book is
     * trading; the intrinsic value is the fallback. In the Iranian market the
     * premium over intrinsic is routinely tens of percent (§7.5), so measuring
     * a fat finger against intrinsic alone would flag every normal order.
     */
    private function referenceFor(int $instrumentId): ?PricePerFineGram
    {
        return $this->prices->lastPrice($instrumentId)
            ?? $this->prices->referencePrice($instrumentId);
    }
}
