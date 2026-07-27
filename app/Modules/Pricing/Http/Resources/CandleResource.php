<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Resources;

use App\Modules\Pricing\Infrastructure\Models\PriceCandle;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * One OHLC bucket. Prices are integer rial per fine gram; volume is integer
 * milligrams. No display companions by default — a charting client plots the
 * numbers and formats its own axis labels.
 *
 * @mixin PriceCandle
 */
final class CandleResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PriceCandle $candle */
        $candle = $this->resource;

        return [
            'interval' => $candle->interval_code->value,
            'opened_at' => Display::iso($candle->opened_at),
            'open_rial' => (int) $candle->open_price,
            'high_rial' => (int) $candle->high_price,
            'low_rial' => (int) $candle->low_price,
            'close_rial' => (int) $candle->close_price,
            'volume_mg' => (int) $candle->volume_mg,
            'trade_count' => (int) $candle->trade_count,
        ];
    }
}
