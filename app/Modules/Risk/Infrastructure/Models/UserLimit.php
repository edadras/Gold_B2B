<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property int $max_order_mg
 * @property int $max_daily_volume_mg
 * @property int|null $requires_approval_above_mg
 * @property bool $is_active
 */
final class UserLimit extends Model
{
    protected $table = 'user_limits';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'max_order_mg' => 'int',
            'max_daily_volume_mg' => 'int',
            'requires_approval_above_mg' => 'int',
            'is_active' => 'bool',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
