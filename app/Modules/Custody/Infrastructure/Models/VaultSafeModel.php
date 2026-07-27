<?php

declare(strict_types=1);

namespace App\Modules\Custody\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id */
final class VaultSafeModel extends Model
{
    protected $table = 'vault_safes';

    protected $guarded = [];

    protected $casts = [
        'vault_id' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function shelves(): HasMany
    {
        return $this->hasMany(VaultShelfModel::class, 'vault_safe_id');
    }
}
