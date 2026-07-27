<?php

declare(strict_types=1);

namespace App\Modules\Custody\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/** @property int $id */
final class VaultBoxModel extends Model
{
    protected $table = 'vault_boxes';

    protected $guarded = [];

    protected $casts = [
        'vault_id' => 'int',
        'vault_safe_id' => 'int',
        'vault_shelf_id' => 'int',
        'capacity_gross_mg' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function acceptsLots(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
