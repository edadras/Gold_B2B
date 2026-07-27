<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only: rows are inserted and never updated or deleted.
 *
 * @property int $id
 * @property int $dispute_id
 * @property string $actor_type
 * @property string $action
 * @property ?string $from_status
 * @property ?string $to_status
 */
final class DisputeTimelineModel extends Model
{
    protected $table = 'dispute_timeline';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'dispute_id' => 'int',
        'actor_user_id' => 'int',
        'actor_org_id' => 'int',
        'occurred_at' => 'datetime',
    ];
}
