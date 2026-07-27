<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure\Models;

use App\Modules\Pricing\Domain\AlertCondition;
use App\Modules\Pricing\Domain\AlertStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property int $instrument_id
 * @property AlertCondition $condition
 * @property int $threshold
 * @property int $window_seconds
 * @property bool $is_recurring
 * @property AlertStatus $status
 * @property CarbonImmutable|null $triggered_at
 */
final class PriceAlert extends Model
{
    protected $table = 'price_alerts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'condition' => AlertCondition::class,
            'status' => AlertStatus::class,
            'threshold' => 'int',
            'window_seconds' => 'int',
            'is_recurring' => 'bool',
            'triggered_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', AlertStatus::ACTIVE->value);
    }
}
