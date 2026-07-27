<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Events\UserRoleChanged;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * Grants and revokes roles.
 *
 * Two invariants:
 *   - a platform role is never attached to a member organisation's user;
 *   - an organisation always keeps at least one OWNER, otherwise nobody can
 *     ever administer it again.
 */
final class AssignRolesService
{
    /**
     * Replace the user's role set with exactly the given roles.
     *
     * @param  list<RoleEnum>  $roles
     */
    public function sync(User $user, array $roles, ?int $actorUserId = null): User
    {
        return $this->apply($user, $roles, mode: 'sync', actorUserId: $actorUserId, silent: false);
    }

    /**
     * Add roles, keeping the ones already held.
     *
     * @param  list<RoleEnum>  $roles
     * @param  bool  $silent  suppress the UserRoleChanged event — used during
     *                        registration, where UserRegistered already covers it
     */
    public function grant(User $user, array $roles, ?int $actorUserId = null, bool $silent = false): User
    {
        return $this->apply($user, $roles, mode: 'grant', actorUserId: $actorUserId, silent: $silent);
    }

    /** @param  list<RoleEnum>  $roles */
    public function revoke(User $user, array $roles, ?int $actorUserId = null): User
    {
        return $this->apply($user, $roles, mode: 'revoke', actorUserId: $actorUserId, silent: false);
    }

    /**
     * @param  list<RoleEnum>  $roles
     */
    private function apply(User $user, array $roles, string $mode, ?int $actorUserId, bool $silent): User
    {
        $organizationId = (int) $user->organization_id;

        foreach ($roles as $role) {
            if ($role->isPlatformRole() && ! $this->isPlatformOrganization($organizationId)) {
                throw new OperationNotPermittedException(
                    'platform_role_outside_platform_organization:'.$role->value
                );
            }
        }

        $before = array_map(static fn (RoleEnum $r): string => $r->value, $user->roleEnums());

        $after = DB::transaction(function () use ($user, $roles, $mode, $actorUserId, $organizationId): array {
            $requested = $this->resolveRoleIds($roles);

            $pivotData = [];
            foreach ($requested as $roleId) {
                $pivotData[$roleId] = [
                    'organization_id' => $organizationId,
                    'assigned_by_user_id' => $actorUserId,
                    'assigned_at' => now(),
                ];
            }

            match ($mode) {
                'sync' => $user->roles()->sync($pivotData),
                'grant' => $user->roles()->syncWithoutDetaching($pivotData),
                'revoke' => $user->roles()->detach(array_keys($pivotData)),
                default => throw new RuntimeException("Unknown role assignment mode: {$mode}"),
            };

            $user->unsetRelation('roles');
            $user->forgetPermissionCache();

            $this->assertOrganizationKeepsAnOwner($organizationId);

            return array_map(static fn (RoleEnum $r): string => $r->value, $user->roleEnums());
        });

        if (! $silent && $before !== $after) {
            Event::dispatch(new UserRoleChanged(
                userId: (int) $user->id,
                organizationId: $organizationId,
                previousRoles: array_values($before),
                currentRoles: array_values($after),
                actorUserId: $actorUserId,
                occurredAt: now()->toIso8601String(),
            ));
        }

        return $user;
    }

    /**
     * @param  list<RoleEnum>  $roles
     * @return list<int>
     */
    private function resolveRoleIds(array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        $names = array_map(static fn (RoleEnum $r): string => $r->value, $roles);

        /** @var list<int> $ids */
        $ids = Role::query()->whereIn('name', $names)->pluck('id')->all();

        if (count($ids) !== count(array_unique($names))) {
            throw new RuntimeException(
                'Roles table is not seeded; run RolesAndPermissionsSeeder before assigning roles.'
            );
        }

        return $ids;
    }

    /**
     * The operator's staff live in the single organisation flagged
     * `is_platform`. Only there may a PLATFORM_* role be granted, and only
     * there is the "must retain an owner" rule inapplicable.
     */
    private function isPlatformOrganization(int $organizationId): bool
    {
        return (bool) Organization::query()->whereKey($organizationId)->value('is_platform');
    }

    private function assertOrganizationKeepsAnOwner(int $organizationId): void
    {
        if ($this->isPlatformOrganization($organizationId)) {
            return;
        }

        $ownerCount = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.organization_id', $organizationId)
            ->where('roles.name', RoleEnum::OWNER->value)
            ->count();

        if ($ownerCount === 0) {
            throw new OperationNotPermittedException('organization_must_retain_an_owner');
        }
    }
}
