<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\PermissionChecker;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Exceptions\ForbiddenException;

/**
 * Adapter that lets the HTTP layer ask Identity "may this caller do this?"
 * without importing Identity.
 *
 * It is a thin shell over PermissionChecker, which is where the real rule
 * lives: BOTH the role grant AND the tenancy match must hold, plus the
 * organisation-status gate. Nothing here re-implements any of that — the shell
 * exists only to translate a dotted string into a Permission and a denial
 * reason into the documented HTTP error code.
 *
 * Users are memoised per request: an endpoint that authorises three resources
 * in a loop should not run three identical role queries.
 *
 * @internal bound to AuthorizationGateway by IdentityServiceProvider
 */
final class IdentityAuthorizationGateway implements AuthorizationGateway
{
    /** @var array<int, User|null> */
    private array $users = [];

    public function __construct(private readonly PermissionChecker $checker) {}

    public function allows(int $userId, string $permission, ?int $resourceOrganizationId = null): bool
    {
        return $this->denialReason($userId, $permission, $resourceOrganizationId) === null;
    }

    public function authorize(int $userId, string $permission, ?int $resourceOrganizationId = null): void
    {
        $reason = $this->denialReason($userId, $permission, $resourceOrganizationId);

        if ($reason !== null) {
            throw ForbiddenException::fromDenialReason($reason);
        }
    }

    public function permissionsFor(int $userId): array
    {
        return $this->user($userId)?->permissionNames() ?? [];
    }

    public function rolesFor(int $userId): array
    {
        $user = $this->user($userId);

        if ($user === null) {
            return [];
        }

        return array_map(
            static fn (\App\Modules\Identity\Domain\Role $role): string => $role->value,
            $user->roleEnums(),
        );
    }

    private function denialReason(int $userId, string $permission, ?int $resourceOrganizationId): ?string
    {
        $user = $this->user($userId);

        if ($user === null) {
            return 'user_not_active';
        }

        $enum = Permission::tryFrom($permission);

        if ($enum === null) {
            // An unknown permission name is a bug in the caller, not a policy
            // decision. Fail closed rather than silently allowing.
            return 'missing_permission:'.$permission;
        }

        return $this->checker->denialReason($user, $enum, $resourceOrganizationId);
    }

    private function user(int $userId): ?User
    {
        if (array_key_exists($userId, $this->users)) {
            return $this->users[$userId];
        }

        /** @var User|null $user */
        $user = User::query()->with('roles')->find($userId);

        return $this->users[$userId] = $user;
    }
}
