<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\PermissionChecker;
use App\Modules\Identity\Contracts\IdentityDirectory;
use App\Modules\Identity\Contracts\OrganizationSnapshot;
use App\Modules\Identity\Contracts\UserSnapshot;
use App\Modules\Identity\Domain\AuthorityType;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\Representative;
use App\Modules\Identity\Infrastructure\Models\User;

final class EloquentIdentityDirectory implements IdentityDirectory
{
    public function __construct(
        private readonly PermissionChecker $permissions,
    ) {}

    public function findOrganization(int $organizationId): ?OrganizationSnapshot
    {
        $organization = Organization::query()->find($organizationId);

        if ($organization === null) {
            return null;
        }

        return new OrganizationSnapshot(
            id: (int) $organization->id,
            type: $organization->type->value,
            status: $organization->status->value,
            displayName: (string) $organization->display_name,
            riskLevel: $organization->risk_level->value,
            canTrade: $organization->status->canTrade(),
            canSettle: $organization->status->canSettle(),
        );
    }

    public function findUser(int $userId): ?UserSnapshot
    {
        $user = User::query()->with('roles')->find($userId);

        if ($user === null) {
            return null;
        }

        return new UserSnapshot(
            id: (int) $user->id,
            organizationId: (int) $user->organization_id,
            branchId: $user->branch_id === null ? null : (int) $user->branch_id,
            fullName: (string) $user->full_name,
            status: $user->status->value,
            roles: array_map(static fn (RoleEnum $r): string => $r->value, $user->roleEnums()),
            permissions: $user->permissionNames(),
        );
    }

    public function organizationCanTrade(int $organizationId): bool
    {
        return $this->findOrganization($organizationId)?->canTrade ?? false;
    }

    public function organizationCanSettle(int $organizationId): bool
    {
        return $this->findOrganization($organizationId)?->canSettle ?? false;
    }

    public function userMayActOn(int $userId, string $permission, int $organizationId): bool
    {
        $user = User::query()->with('roles')->find($userId);
        $permissionEnum = Permission::tryFrom($permission);

        if ($user === null || $permissionEnum === null) {
            return false;
        }

        return $this->permissions->allows($user, $permissionEnum, $organizationId);
    }

    public function userHasRepresentativeAuthority(int $userId, string $authorityType): bool
    {
        $needed = AuthorityType::tryFrom($authorityType);

        if ($needed === null) {
            return false;
        }

        return Representative::query()
            ->where('user_id', $userId)
            ->active()
            ->get()
            ->contains(static fn (Representative $r): bool => $r->isEffective($needed));
    }
}
