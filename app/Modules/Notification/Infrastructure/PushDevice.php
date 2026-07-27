<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 * @property string $platform
 * @property string $token
 */
final class PushDevice extends Model
{
    protected $table = 'push_devices';

    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer',
        'organization_id' => 'integer',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
