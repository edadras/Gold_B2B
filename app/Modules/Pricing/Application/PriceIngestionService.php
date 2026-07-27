<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Contracts\IncomingTick;
use App\Modules\Pricing\Domain\PriceType;
use App\Modules\Pricing\Domain\RejectionReason;
use App\Modules\Pricing\Domain\SourceStatus;
use App\Modules\Pricing\Events\PriceTickAccepted;
use App\Modules\Pricing\Events\PriceTickRejected;
use App\Modules\Pricing\Infrastructure\Models\PriceSource;
use App\Modules\Pricing\Infrastructure\Models\PriceTick;
use App\Modules\Shared\Support\IntMath;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * The five filters of docs/03-domain/07-pricing.md §7.4, applied in the order
 * the document lists them:
 *
 *   1) staleness            → STALE
 *   2) deviation from the last accepted value of the same source → OUTLIER
 *   3) deviation from peer sources → flag + substitute the median (never rejects)
 *   4) sane range           → OUT_OF_RANGE
 *   5) monotonic timestamps → OUT_OF_ORDER
 *
 * Everything that arrives is persisted, accepted or not.
 */
final class PriceIngestionService
{
    private const BPS_SCALE = 10_000;

    public function __construct(private readonly Dispatcher $events) {}

    public function ingest(IncomingTick $incoming): IngestionResult
    {
        $source = PriceSource::query()->findOrFail($incoming->sourceId);

        $reason = null;
        $isCrossSourceOutlier = false;
        $median = null;
        $effectiveValue = $incoming->value;

        // Filter 1 — freshness.
        if ($this->isStale($incoming, $source)) {
            $reason = RejectionReason::STALE;
        }

        // Filter 2 — deviation from our own last accepted value.
        if ($reason === null && $this->deviatesFromLastAccepted($incoming, $source)) {
            $reason = RejectionReason::OUTLIER;
        }

        // Filter 3 — cross-source agreement. Flags, never rejects.
        if ($reason === null) {
            $peers = $this->peerValues($incoming, $source);

            if ($peers !== []) {
                $median = $this->median([...$peers, $incoming->value]);
                $threshold = (int) config('goldb2b.pricing.cross_source_deviation_bps', 200);

                if ($median > 0 && $this->deviationBps($incoming->value, $median) > $threshold) {
                    $isCrossSourceOutlier = true;
                    $effectiveValue = $median;
                }
            }
        }

        // Filter 4 — sane range.
        if ($reason === null && $this->isOutOfRange($incoming, $source)) {
            $reason = RejectionReason::OUT_OF_RANGE;
        }

        // Filter 5 — monotonic timestamps.
        if ($reason === null && $this->isOutOfOrder($incoming)) {
            $reason = RejectionReason::OUT_OF_ORDER;
        }

        $accepted = $reason === null;

        $tick = DB::transaction(function () use ($incoming, $source, $accepted, $reason, $isCrossSourceOutlier, $effectiveValue): PriceTick {
            $tick = PriceTick::query()->create([
                'source_id' => $incoming->sourceId,
                'price_type' => $incoming->priceType->value,
                'value' => $incoming->value,
                'scale' => $incoming->scale,
                'observed_at' => $incoming->observedAt,
                'received_at' => $incoming->receivedAt,
                'is_accepted' => $accepted,
                'rejection_reason' => $reason?->value,
                'is_cross_source_outlier' => $isCrossSourceOutlier,
                'effective_value' => $isCrossSourceOutlier ? $effectiveValue : null,
            ]);

            $this->recordSourceOutcome($source, $accepted, $reason);

            return $tick;
        });

        // Rule 3: events only after the transaction has committed.
        if ($accepted) {
            $this->events->dispatch(new PriceTickAccepted(
                tickId: (int) $tick->id,
                sourceId: $incoming->sourceId,
                priceType: $incoming->priceType->value,
                value: $incoming->value,
                effectiveValue: $effectiveValue,
                isCrossSourceOutlier: $isCrossSourceOutlier,
                observedAt: $incoming->observedAt->toIso8601String(),
            ));
        } else {
            $this->events->dispatch(new PriceTickRejected(
                tickId: (int) $tick->id,
                sourceId: $incoming->sourceId,
                priceType: $incoming->priceType->value,
                value: $incoming->value,
                reason: $reason->value,
                needsManualConfirmation: $reason->needsManualConfirmation(),
            ));
        }

        return new IngestionResult(
            tickId: (int) $tick->id,
            accepted: $accepted,
            effectiveValue: $effectiveValue,
            reason: $reason,
            isCrossSourceOutlier: $isCrossSourceOutlier,
            medianValue: $median,
        );
    }

    private function isStale(IncomingTick $incoming, PriceSource $source): bool
    {
        return $incoming->ageSeconds() > $source->max_staleness_s;
    }

    private function deviatesFromLastAccepted(IncomingTick $incoming, PriceSource $source): bool
    {
        $last = $this->lastAcceptedTick($incoming->sourceId, $incoming->priceType);

        if ($last === null || $last->value === 0) {
            return false;
        }

        return $this->deviationBps($incoming->value, $last->value) > $source->max_deviation_bps;
    }

    /**
     * Latest accepted value from every *other* usable source of the same type,
     * limited to observations still inside that source's staleness window.
     *
     * @return list<int>
     */
    private function peerValues(IncomingTick $incoming, PriceSource $source): array
    {
        $peers = PriceSource::query()
            ->usable()
            ->ofType($incoming->priceType)
            ->where('id', '!=', $source->id)
            ->get();

        $values = [];

        foreach ($peers as $peer) {
            $tick = $this->lastAcceptedTick((int) $peer->id, $incoming->priceType);

            if ($tick === null) {
                continue;
            }

            if ($tick->observed_at->diffInSeconds($incoming->receivedAt, absolute: false) > $peer->max_staleness_s) {
                continue;
            }

            $values[] = $tick->usableValue();
        }

        return $values;
    }

    private function isOutOfRange(IncomingTick $incoming, PriceSource $source): bool
    {
        if ($incoming->value <= 0) {
            return true;
        }

        $max = $source->max_sane_value > 0
            ? $source->max_sane_value
            : $incoming->priceType->defaultMaxSaneValue();

        return $incoming->value < $source->min_sane_value || $incoming->value > $max;
    }

    private function isOutOfOrder(IncomingTick $incoming): bool
    {
        $last = $this->lastAcceptedTick($incoming->sourceId, $incoming->priceType);

        if ($last === null) {
            return false;
        }

        return $incoming->observedAt->lessThanOrEqualTo($last->observed_at);
    }

    private function lastAcceptedTick(int $sourceId, PriceType $type): ?PriceTick
    {
        return PriceTick::query()
            ->accepted()
            ->where('source_id', $sourceId)
            ->where('price_type', $type->value)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @param list<int> $values */
    private function median(array $values): int
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return intdiv($values[$middle - 1] + $values[$middle], 2);
    }

    private function deviationBps(int $value, int $reference): int
    {
        if ($reference === 0) {
            return PHP_INT_MAX;
        }

        return IntMath::mulDivFloor(abs($value - $reference), self::BPS_SCALE, abs($reference));
    }

    private function recordSourceOutcome(PriceSource $source, bool $accepted, ?RejectionReason $reason): void
    {
        if ($accepted) {
            $source->forceFill([
                'last_success_at' => now(),
                'last_error' => null,
                'status' => $source->status === SourceStatus::DOWN
                    ? SourceStatus::DEGRADED->value
                    : $source->status->value,
            ])->save();

            return;
        }

        $source->forceFill([
            'last_error' => $reason?->value,
        ])->save();
    }
}
