<?php

declare(strict_types=1);

namespace App\Modules\Trading\Infrastructure\Models;

use App\Modules\Trading\Domain\MarketSessionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $instrument_id
 * @property CarbonImmutable $session_date
 * @property MarketSessionStatus $status
 * @property ?CarbonImmutable $resume_at
 * @property int $volume_mg
 * @property int $trade_count
 */
final class MarketSession extends Model
{
    protected $table = 'market_sessions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'session_date' => 'immutable_date',
            'status' => MarketSessionStatus::class,
            'pre_open_at' => 'immutable_datetime',
            'opens_at' => 'immutable_datetime',
            'closes_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
            'resume_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'opening_price_rial' => 'integer',
            'closing_price_rial' => 'integer',
            'high_price_rial' => 'integer',
            'low_price_rial' => 'integer',
            'volume_mg' => 'integer',
            'trade_count' => 'integer',
        ];
    }
}
