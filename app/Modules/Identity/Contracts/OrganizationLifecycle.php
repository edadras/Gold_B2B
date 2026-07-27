<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Domain\OrganizationStatus;

/**
 * The slice of organization lifecycle control other modules are allowed to use.
 *
 * Kyc in particular must move an organization to VERIFIED and then ACTIVE when a
 * review is approved, and RESTRICTED when a licence lapses. Exposing that here
 * keeps Kyc off Identity's Eloquent models and application services, which is
 * what the module boundary rule requires — see docs/02-architecture/02-modules.md §2.3.
 */
interface OrganizationLifecycle
{
    public function currentStatus(int $organizationId): OrganizationStatus;

    /**
     * Move an organization to a new status on behalf of a human actor.
     *
     * @throws \App\Modules\Shared\Exceptions\InvalidStateTransitionException
     */
    public function transition(
        int $organizationId,
        OrganizationStatus $target,
        int $actorUserId,
        ?string $reason = null,
        array $metadata = [],
    ): void;

    /** Move an organization automatically; a reason is mandatory for the audit trail. */
    public function transitionBySystem(
        int $organizationId,
        OrganizationStatus $target,
        string $reason,
        array $metadata = [],
    ): void;

    /**
     * Whether a national or legal id is already registered to another
     * organization. Kyc needs this to reject duplicate registrations without
     * being able to read the encrypted column itself.
     */
    public function identifierInUse(string $identifier, ?int $exceptOrganizationId = null): bool;

    /** Whether a user lacks a permission within an organization. */
    public function userLacksPermission(int $userId, string $permission, int $organizationId): bool;
}
