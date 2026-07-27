<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure\Models;

use App\Modules\Pricing\Domain\CandleInterval;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $instrument_id
 * @property CandleInterval $interval_code
 * @property \Carbon\CarbonImmutable $opened_at
 * @property int $open_price
 * @property int $high_price
 * @property int $low_price
 * @property int $close_price
 * @property int $volume_mg
 * @property int $trade_count
 */
final class PriceCandle extends Model
{
    protected $table = 'price_candles';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'interval_code' => CandleInterval::class,
            'opened_at' => 'immutable_datetime',
            'open_price' => 'int',
            'high_price' => 'int',
            'low_price' => 'int',
            'close_price' => 'int',
            'volume_mg' => 'int',
            'trade_count' => 'int',
        ];
    }
}
