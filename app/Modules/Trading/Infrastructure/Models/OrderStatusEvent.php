<?php

declare(strict_types=1);

namespace App\Modules\Trading\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only: rule 1 of docs/11-appendix/02-state-machines.md §2.14.
 *
 * @property int $id
 * @property int $order_id
 * @property ?string $from_status
 * @property string $to_status
 */
final class OrderStatusEvent extends Model
{
    public const ACTOR_USER = 'USER';

    public const ACTOR_SYSTEM = 'SYSTEM';

    public $timestamps = false;

    protected $table = 'order_status_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
