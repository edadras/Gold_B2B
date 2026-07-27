<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Resources;

use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use App\Modules\Trading\Infrastructure\Models\Instrument;
use Illuminate\Http\Request;

/**
 * A tradable instrument. Everything here is reference data the client caches:
 * tick size, lot size and the order-size band it must validate against locally
 * before it bothers the server.
 *
 * @mixin Instrument
 */
final class InstrumentResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Instrument $instrument */
        $instrument = $this->resource;

        return [
            'code' => (string) $instrument->code,
            'name' => (string) $instrument->name,
            'min_purity_x10' => (int) $instrument->min_purity_x10,
            'quote_unit' => (string) $instrument->quote_unit,
            'settlement_type' => $instrument->settlement_type->value,
            'tick_size_rial' => (int) $instrument->tick_size_rial,
            'lot_size_mg' => (int) $instrument->lot_size_mg,
            'min_order_mg' => (int) $instrument->min_order_mg,
            'max_order_mg' => (int) $instrument->max_order_mg,
            'max_price_deviation_bps' => (int) $instrument->max_price_deviation_bps,
            'status' => $instrument->status->value,
            'is_tradable' => $instrument->isTradable(),
        ] + $this->display($request, [
            'min_purity_display' => Display::purity((int) $instrument->min_purity_x10),
            'min_order_display' => Display::grams((int) $instrument->min_order_mg),
            'max_order_display' => Display::grams((int) $instrument->max_order_mg),
            'tick_size_display' => Display::rial((int) $instrument->tick_size_rial),
        ]);
    }
}
