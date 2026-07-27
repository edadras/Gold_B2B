<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property CarbonImmutable $counter_date
 * @property int $volume_mg
 * @property int $value_rial
 * @property int $trade_count
 */
final class DailyCounter extends Model
{
    protected $table = 'daily_counters';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'counter_date' => 'immutable_date',
            'volume_mg' => 'int',
            'value_rial' => 'int',
            'trade_count' => 'int',
        ];
    }
}
