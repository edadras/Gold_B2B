<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Domain\Permission as PermissionEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 */
class Permission extends Model
{
    protected $table = 'permissions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'requires_dual_control' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role');
    }

    public function toEnum(): ?PermissionEnum
    {
        return PermissionEnum::tryFrom($this->name);
    }
}
