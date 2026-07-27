<?php

declare(strict_types=1);

namespace App\Modules\Custody\Infrastructure\Models;

use App\Modules\Custody\Domain\Enums\LineageOperation;
use Illuminate\Database\Eloquent\Model;

/** @property int $id */
final class LotLineageModel extends Model
{
    protected $table = 'lot_lineage';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'parent_lot_id' => 'int',
        'child_lot_id' => 'int',
        'operation' => LineageOperation::class,
        'operation_id' => 'int',
        'created_at' => 'datetime',
    ];
}
