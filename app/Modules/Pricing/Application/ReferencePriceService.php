<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Domain\IntrinsicValueCalculator;
use App\Modules\Pricing\Domain\PriceSourceMode;
use App\Modules\Pricing\Domain\PriceType;
use App\Modules\Pricing\Infrastructure\Models\ReferencePrice;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns the currently usable ounce and FX prices into a stored intrinsic value
 * (F6) for an instrument. docs/03-domain/07-pricing.md §7.2.
 */
final class ReferencePriceService
{
    public function __construct(
        private readonly PriceSourceFallback $fallback,
        private readonly IntrinsicValueCalculator $intrinsic,
    ) {}

    /**
     * Returns null when either leg is unavailable — the caller (the console
     * command) reports it and PriceSourceFallback has already emitted
     * NoPriceAvailable.
     */
    public function compute(int $instrumentId): ?ReferencePrice
    {
        $ounce = $this->fallback->resolve(PriceType::OUNCE_USD);
        $fx = $this->fallback->resolve(PriceType::USD_IRR);

        if (! $ounce->hasValue() || ! $fx->hasValue()) {
            return null;
        }

        $price = $this->intrinsic->pricePerFineGram($ounce->value, $fx->value);

        return DB::transaction(fn (): ReferencePrice => ReferencePrice::query()->create([
            'instrument_id' => $instrumentId,
            'fine_gram_rial' => $price->rial,
            'ounce_usd_micro' => $ounce->value,
            'usd_irr' => $fx->value,
            'computed_at' => CarbonImmutable::now(),
            'source_tick_ids' => array_values(array_filter([$ounce->tickId, $fx->tickId])),
            'mode' => $this->mode($ounce, $fx)->value,
        ]));
    }

    public function latest(int $instrumentId): ?PricePerFineGram
    {
        /** @var ReferencePrice|null $row */
        $row = ReferencePrice::query()
            ->where('instrument_id', $instrumentId)
            ->orderByDesc('computed_at')
            ->orderByDesc('id')
            ->first();

        return $row === null ? null : PricePerFineGram::fromRial($row->fine_gram_rial);
    }

    private function mode(PriceResolution $ounce, PriceResolution $fx): PriceSourceMode
    {
        if ($ounce->mode === PriceSourceMode::MANUAL || $fx->mode === PriceSourceMode::MANUAL) {
            return PriceSourceMode::MANUAL;
        }

        if ($ounce->mode === PriceSourceMode::FALLBACK || $fx->mode === PriceSourceMode::FALLBACK) {
            return PriceSourceMode::FALLBACK;
        }

        return PriceSourceMode::PRIMARY;
    }
}
