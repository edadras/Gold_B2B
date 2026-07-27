<?php

declare(strict_types=1);

namespace App\Modules\Custody\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only. Never updated, never deleted.
 *
 * @property int $id
 */
final class LotStatusEventModel extends Model
{
    protected $table = 'lot_status_events';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'gold_lot_id' => 'int',
        'actor_user_id' => 'int',
        'reference_id' => 'int',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];
}
