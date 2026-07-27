<?php

declare(strict_types=1);

namespace App\Modules\Custody\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/** @property int $id */
final class VaultWaybillModel extends Model
{
    protected $table = 'vault_waybills';

    protected $guarded = [];

    protected $casts = [
        'custody_operation_id' => 'int',
        'vault_id' => 'int',
        'owner_organization_id' => 'int',
        'gold_lot_ids' => 'array',
        'code_attempts' => 'int',
        'issued_by_user_id' => 'int',
        'code_expires_at' => 'datetime',
        'code_used_at' => 'datetime',
        'issued_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
