<?php

declare(strict_types=1);

namespace App\Modules\Custody\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id */
final class VaultModel extends Model
{
    protected $table = 'vaults';

    protected $guarded = [];

    protected $casts = [
        'operator_organization_id' => 'int',
        'capacity_fine_mg' => 'int',
        'coverage_amount_rial' => 'int',
        'coverage_expires_at' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function safes(): HasMany
    {
        return $this->hasMany(VaultSafeModel::class, 'vault_id');
    }

    public function boxes(): HasMany
    {
        return $this->hasMany(VaultBoxModel::class, 'vault_id');
    }

    public function acceptsDeposits(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
