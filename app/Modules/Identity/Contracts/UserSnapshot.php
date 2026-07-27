<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

final readonly class UserSnapshot
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    public function __construct(
        public int $id,
        public int $organizationId,
        public ?int $branchId,
        public string $fullName,
        public string $status,
        public array $roles,
        public array $permissions,
    ) {}

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
