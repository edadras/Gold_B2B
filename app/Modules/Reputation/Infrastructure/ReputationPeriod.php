<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Infrastructure;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $organization_id
 * @property string $period_type
 * @property string $period_start
 */
final class ReputationPeriod extends Model
{
    public $timestamps = false;

    protected $table = 'reputation_periods';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'trades' => 'integer',
        'volume_mg' => 'integer',
        'settlements_total' => 'integer',
        'settlements_on_time' => 'integer',
        'on_time_rate_bps' => 'integer',
        'disputes' => 'integer',
        'period_start' => 'date',
    ];
}
