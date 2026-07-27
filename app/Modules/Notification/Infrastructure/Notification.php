<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure;

use App\Modules\Notification\Domain\NotificationCode;
use App\Modules\Notification\Domain\Priority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The in-app notification row — which is also the record every external
 * delivery points back at.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $user_id
 * @property string $code
 * @property Priority $priority
 * @property int|null $aggregate_count
 */
final class Notification extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'notifications';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'user_id' => 'integer',
        'subject_id' => 'integer',
        'aggregate_count' => 'integer',
        'priority' => Priority::class,
        'action_payload' => 'array',
        'aggregate_window_start' => 'datetime',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function notificationCode(): ?NotificationCode
    {
        return NotificationCode::tryFrom($this->code);
    }

    public function isAggregate(): bool
    {
        return $this->aggregate_count !== null;
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->read_at = now();
            $this->save();
        }
    }

    /**
     * The feed as a user should see it (§15.4 rule 3).
     *
     * Individual rows are kept — each still links to its own subject and a
     * support agent may need them — but once an aggregate covers them they stop
     * being shown, which is what "collapse into one aggregate" means to the
     * person reading the list.
     *
     * @param  Builder<self>  $query
     */
    public function scopeFeedFor(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId)
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('notifications as agg')
                    ->whereColumn('agg.user_id', 'notifications.user_id')
                    ->whereColumn('agg.code', 'notifications.code')
                    ->whereNotNull('agg.aggregate_count')
                    ->whereNull('notifications.aggregate_count')
                    ->whereColumn('notifications.created_at', '>=', 'agg.aggregate_window_start');
            })
            ->orderByDesc('created_at');
    }
}
