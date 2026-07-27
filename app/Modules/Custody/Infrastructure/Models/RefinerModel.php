<?php

declare(strict_types=1);

namespace App\Modules\Custody\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/** @property int $id */
final class RefinerModel extends Model
{
    protected $table = 'refiners';

    protected $guarded = [];

    protected $casts = [
        'average_loss_bps' => 'int',
        'total_processed_mg' => 'int',
        'variance_history' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
