<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Contracts\OrganizationLifecycle;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\User;
use RuntimeException;

/**
 * Adapter exposing organization lifecycle control to other modules.
 *
 * Everything here already exists inside Identity; this class only narrows it to
 * the id-based surface declared in the contract, so callers such as Kyc never
 * hold an Identity Eloquent model.
 */
final readonly class OrganizationLifecycleService implements OrganizationLifecycle
{
    public function __construct(
        private OrganizationStateMachine $stateMachine,
        private PermissionChecker $permissions,
    ) {}

    public function currentStatus(int $organizationId): OrganizationStatus
    {
        return $this->organization($organizationId)->status;
    }

    public function transition(
        int $organizationId,
        OrganizationStatus $target,
        int $actorUserId,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        $this->stateMachine->transition(
            $this->organization($organizationId),
            $target,
            $actorUserId,
            $reason,
            $metadata,
        );
    }

    public function transitionBySystem(
        int $organizationId,
        OrganizationStatus $target,
        string $reason,
        array $metadata = [],
    ): void {
        $this->stateMachine->transitionBySystem(
            $this->organization($organizationId),
            $target,
            $reason,
            $metadata,
        );
    }

    public function identifierInUse(string $identifier, ?int $exceptOrganizationId = null): bool
    {
        if ($identifier === '') {
            return false;
        }

        return Organization::query()
            ->where(function ($query) use ($identifier): void {
                $query->where('national_id_hash', $identifier)
                    ->orWhere('legal_id_hash', $identifier);
            })
            ->when(
                $exceptOrganizationId !== null,
                fn ($query) => $query->whereKeyNot($exceptOrganizationId),
            )
            ->exists();
    }

    public function userLacksPermission(int $userId, string $permission, int $organizationId): bool
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return true;
        }

        $resolved = Permission::tryFrom($permission);

        if ($resolved === null) {
            throw new RuntimeException("Unknown permission: {$permission}");
        }

        return $this->permissions->denies($user, $resolved, $organizationId);
    }

    private function organization(int $organizationId): Organization
    {
        return Organization::query()->findOrFail($organizationId);
    }
}
