<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Resources;

use App\Modules\Pricing\Domain\AlertCondition;
use App\Modules\Pricing\Infrastructure\Models\PriceAlert;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * A member's own price alert.
 *
 * `threshold` carries two different units depending on the condition — rial for
 * ABOVE/BELOW, basis points for CHANGE_PERCENT — so `threshold_unit` is
 * published alongside it rather than leaving the client to infer it.
 *
 * @mixin PriceAlert
 */
final class PriceAlertResource extends ApiResource
{
    public function __construct(mixed $resource, private readonly ?string $instrumentCode = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PriceAlert $alert */
        $alert = $this->resource;

        $isRate = $alert->condition === AlertCondition::CHANGE_PERCENT;

        return [
            'id' => (int) $alert->id,
            'instrument' => $this->instrumentCode,
            'instrument_id' => (int) $alert->instrument_id,
            'condition' => $alert->condition->value,
            'threshold' => (int) $alert->threshold,
            'threshold_unit' => $isRate ? 'BPS' : 'RIAL',
            'window_seconds' => (int) $alert->window_seconds,
            'is_recurring' => (bool) $alert->is_recurring,
            'status' => $alert->status->value,
            'triggered_at' => Display::iso($alert->triggered_at),
        ] + $this->display($request, [
            'threshold_display' => $isRate
                ? Display::bps((int) $alert->threshold)
                : Display::rial((int) $alert->threshold),
            'triggered_at_jalali' => Display::jalali($alert->triggered_at),
        ]);
    }
}
