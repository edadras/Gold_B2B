<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Domain\PriceSourceMode;
use App\Modules\Pricing\Domain\PriceType;
use App\Modules\Pricing\Domain\SourceStatus;
use App\Modules\Pricing\Events\NoPriceAvailable;
use App\Modules\Pricing\Events\PriceSourceDegraded;
use App\Modules\Pricing\Infrastructure\Models\PriceSource;
use App\Modules\Pricing\Infrastructure\Models\PriceTick;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * The fallback ladder of docs/03-domain/07-pricing.md §7.4:
 *
 *   priority 1 healthy?          → use it
 *   priority 2 healthy?          → use it, mark the skipped sources DEGRADED
 *   no automatic source at all?  → MANUAL mode, operator-entered price
 *   nothing for more than 5 min? → signal that the market must halt
 *
 * This class never halts the market itself: it emits NoPriceAvailable with
 * shouldHaltMarket = true and Trading owns the MarketSession state machine.
 */
final class PriceSourceFallback
{
    public function __construct(private readonly Dispatcher $events) {}

    public function resolve(PriceType $type, ?CarbonImmutable $now = null): PriceResolution
    {
        $now ??= CarbonImmutable::now();

        /** @var list<PriceSource> $sources */
        $sources = PriceSource::query()
            ->ofType($type)
            ->where('is_enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->all();

        $automatic = array_values(array_filter($sources, fn (PriceSource $s): bool => $s->isAutomatic()));
        $manual = array_values(array_filter($sources, fn (PriceSource $s): bool => ! $s->isAutomatic()));

        $skipped = [];

        foreach ($automatic as $index => $source) {
            $tick = $source->status->isUsable() ? $this->freshTick($source, $type, $now) : null;

            if ($tick === null) {
                $skipped[] = $source;

                continue;
            }

            if ($skipped !== []) {
                $this->degrade($skipped, $type);
            }

            return new PriceResolution(
                priceType: $type,
                mode: $index === 0 ? PriceSourceMode::PRIMARY : PriceSourceMode::FALLBACK,
                value: $tick->usableValue(),
                scale: $tick->scale,
                sourceId: (int) $source->id,
                sourceCode: $source->code,
                tickId: (int) $tick->id,
                observedAt: $tick->observed_at,
            );
        }

        if ($skipped !== []) {
            $this->degrade($skipped, $type);
        }

        foreach ($manual as $source) {
            $tick = $source->status->isUsable() ? $this->freshTick($source, $type, $now) : null;

            if ($tick === null) {
                continue;
            }

            return new PriceResolution(
                priceType: $type,
                mode: PriceSourceMode::MANUAL,
                value: $tick->usableValue(),
                scale: $tick->scale,
                sourceId: (int) $source->id,
                sourceCode: $source->code,
                tickId: (int) $tick->id,
                observedAt: $tick->observed_at,
            );
        }

        return $this->noPrice($type, $now);
    }

    private function freshTick(PriceSource $source, PriceType $type, CarbonImmutable $now): ?PriceTick
    {
        /** @var PriceTick|null $tick */
        $tick = PriceTick::query()
            ->accepted()
            ->where('source_id', $source->id)
            ->where('price_type', $type->value)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();

        if ($tick === null) {
            return null;
        }

        $age = (int) $tick->observed_at->diffInSeconds($now, absolute: false);

        return $age > $source->max_staleness_s ? null : $tick;
    }

    /** @param list<PriceSource> $sources */
    private function degrade(array $sources, PriceType $type): void
    {
        $degraded = [];

        DB::transaction(function () use ($sources, &$degraded): void {
            foreach ($sources as $source) {
                if ($source->status === SourceStatus::DEGRADED) {
                    continue;
                }

                $previous = $source->status;
                $source->forceFill(['status' => SourceStatus::DEGRADED->value])->save();
                $degraded[] = [$source, $previous];
            }
        });

        foreach ($degraded as [$source, $previous]) {
            $this->events->dispatch(new PriceSourceDegraded(
                sourceId: (int) $source->id,
                sourceCode: $source->code,
                priceType: $type->value,
                previousStatus: $previous->value,
                reason: 'NO_FRESH_TICK',
            ));
        }
    }

    private function noPrice(PriceType $type, CarbonImmutable $now): PriceResolution
    {
        /** @var PriceTick|null $latest */
        $latest = PriceTick::query()
            ->accepted()
            ->where('price_type', $type->value)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();

        $seconds = $latest === null
            ? null
            : (int) $latest->observed_at->diffInSeconds($now, absolute: false);

        $haltAfter = (int) config('goldb2b.pricing.no_price_halt_seconds', 300);
        $shouldHalt = $seconds === null || $seconds > $haltAfter;

        $this->events->dispatch(new NoPriceAvailable(
            priceType: $type->value,
            secondsSinceLastPrice: $seconds,
            shouldHaltMarket: $shouldHalt,
        ));

        return PriceResolution::none($type, $seconds, $shouldHalt);
    }
}
