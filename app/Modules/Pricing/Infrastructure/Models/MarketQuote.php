<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $instrument_id
 * @property int|null $best_bid
 * @property int|null $best_bid_qty_mg
 * @property int|null $best_ask
 * @property int|null $best_ask_qty_mg
 * @property int|null $last_price
 * @property int|null $last_qty_mg
 * @property \Carbon\CarbonImmutable|null $last_at
 * @property bool $last_is_stale
 * @property int|null $day_open
 * @property int|null $day_high
 * @property int|null $day_low
 * @property int $day_volume_mg
 * @property int|null $day_vwap
 * @property int $day_vwap_numerator
 * @property int $day_trade_count
 * @property string|null $session_date
 */
final class MarketQuote extends Model
{
    protected $table = 'market_quotes';

    protected $primaryKey = 'instrument_id';

    public $incrementing = false;

    public const CREATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'instrument_id' => 'int',
            'best_bid' => 'int',
            'best_bid_qty_mg' => 'int',
            'best_ask' => 'int',
            'best_ask_qty_mg' => 'int',
            'last_price' => 'int',
            'last_qty_mg' => 'int',
            'last_at' => 'immutable_datetime',
            'last_is_stale' => 'bool',
            'day_open' => 'int',
            'day_high' => 'int',
            'day_low' => 'int',
            'day_volume_mg' => 'int',
            'day_vwap' => 'int',
            'day_vwap_numerator' => 'int',
            'day_trade_count' => 'int',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
