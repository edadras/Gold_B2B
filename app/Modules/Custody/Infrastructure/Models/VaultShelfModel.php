<?php

declare(strict_types=1);

namespace App\Modules\Custody\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id */
final class VaultShelfModel extends Model
{
    protected $table = 'vault_shelves';

    protected $guarded = [];

    protected $casts = [
        'vault_id' => 'int',
        'vault_safe_id' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function boxes(): HasMany
    {
        return $this->hasMany(VaultBoxModel::class, 'vault_shelf_id');
    }
}
