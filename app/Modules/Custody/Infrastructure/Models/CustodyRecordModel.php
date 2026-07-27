<?php

declare(strict_types=1);

namespace App\Modules\Custody\Infrastructure\Models;

use App\Modules\Custody\Domain\Enums\CustodianType;
use Illuminate\Database\Eloquent\Model;

/** @property int $id */
final class CustodyRecordModel extends Model
{
    protected $table = 'custody_records';

    protected $guarded = [];

    protected $casts = [
        'custodian_type' => CustodianType::class,
        'gold_lot_id' => 'int',
        'custodian_id' => 'int',
        'vault_id' => 'int',
        'vault_box_id' => 'int',
        'gross_weight_mg' => 'int',
        'fine_weight_mg' => 'int',
        'received_operation_id' => 'int',
        'released_operation_id' => 'int',
        'received_by_user_id' => 'int',
        'released_by_user_id' => 'int',
        'received_at' => 'datetime',
        'released_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
