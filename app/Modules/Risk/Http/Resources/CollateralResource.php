<?php

declare(strict_types=1);

namespace App\Modules\Risk\Http\Resources;

use App\Modules\Risk\Infrastructure\Models\Collateral;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * One pledge — `GET /risk/collaterals` (§2.15).
 *
 * The accepted value is recomputed here from the stored nominal and the stored
 * haircut rather than being read from a column, so a member always sees the two
 * numbers that produced it. Integer arithmetic: bps × rial ÷ 10,000, floored.
 *
 * @mixin Collateral
 */
final class CollateralResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Collateral $collateral */
        $collateral = $this->resource;

        $accepted = intdiv($collateral->nominal_value_rial * $collateral->acceptance_factor_bps, 10_000);

        return [
            'id' => (int) $collateral->id,
            'organization_id' => (int) $collateral->organization_id,
            'type' => $collateral->type->value,
            'type_label' => $collateral->type->label(),
            'status' => $collateral->status->value,
            'counts_towards_coverage' => $collateral->status->countsTowardsCoverage(),
            'nominal_value_rial' => $collateral->nominal_value_rial,
            'fine_weight_mg' => $collateral->fine_weight_mg,
            'acceptance_factor_bps' => $collateral->acceptance_factor_bps,
            'accepted_value_rial' => $accepted,
            'is_price_sensitive' => $collateral->type->isPriceSensitive(),
            'reference' => $collateral->reference,
            'valued_at' => Display::iso($collateral->valued_at),
            'expires_at' => Display::iso($collateral->expires_at),
        ] + $this->display($request, [
            'nominal_value_display' => Display::rial($collateral->nominal_value_rial),
            'accepted_value_display' => Display::rial($accepted),
            'fine_weight_display' => Display::grams($collateral->fine_weight_mg),
            'acceptance_factor_display' => Display::bps($collateral->acceptance_factor_bps),
            'valued_at_jalali' => Display::jalali($collateral->valued_at),
            'expires_at_jalali' => Display::jalali($collateral->expires_at),
        ]);
    }
}
