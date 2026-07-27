<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Domain\Role as RoleEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 */
class Role extends Model
{
    protected $table = 'roles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_platform_role' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user');
    }

    public function toEnum(): ?RoleEnum
    {
        return RoleEnum::tryFrom($this->name);
    }
}
