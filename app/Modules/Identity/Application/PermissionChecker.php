<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\UserStatus;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;

/**
 * The three mandatory layers of docs/02-architecture/04-security.md §4.3:
 * authentication (the caller hands us a User), tenancy, and permission.
 *
 * Tenancy is checked *explicitly* here rather than being left to an Eloquent
 * global scope, because `withoutGlobalScope()` or a raw query bypasses the
 * scope entirely. Every write path must go through allows()/authorize().
 */
final class PermissionChecker
{
    /**
     * @param  int|Organization  $resourceOrganization  the organisation that owns
     *                                                  the resource being acted on
     */
    public function allows(
        User $user,
        Permission $permission,
        int|Organization|null $resourceOrganization = null,
        bool $requireActiveOrganization = true,
    ): bool {
        return $this->denialReason($user, $permission, $resourceOrganization, $requireActiveOrganization) === null;
    }

    public function denies(
        User $user,
        Permission $permission,
        int|Organization|null $resourceOrganization = null,
        bool $requireActiveOrganization = true,
    ): bool {
        return ! $this->allows($user, $permission, $resourceOrganization, $requireActiveOrganization);
    }

    /** @throws OperationNotPermittedException */
    public function authorize(
        User $user,
        Permission $permission,
        int|Organization|null $resourceOrganization = null,
        bool $requireActiveOrganization = true,
    ): void {
        $reason = $this->denialReason($user, $permission, $resourceOrganization, $requireActiveOrganization);

        if ($reason !== null) {
            throw new OperationNotPermittedException($reason);
        }
    }

    /**
     * @return string|null null when allowed, otherwise a machine-readable reason
     */
    public function denialReason(
        User $user,
        Permission $permission,
        int|Organization|null $resourceOrganization = null,
        bool $requireActiveOrganization = true,
    ): ?string {
        // Layer 1 — the login itself must be usable.
        if ($user->status !== UserStatus::ACTIVE) {
            return 'user_not_active';
        }

        // Layer 3 — role grants.
        if (! $user->hasPermission($permission)) {
            return 'missing_permission:'.$permission->value;
        }

        // Platform-scoped permissions are never tenancy-checked: platform staff
        // act *on* other organisations by design. They are, however, only ever
        // granted through platform roles.
        if ($permission->isPlatformScoped()) {
            return $user->isPlatformStaff() ? null : 'not_platform_staff';
        }

        // Layer 2 — tenancy. Resolve the owning organisation of the resource.
        $organization = $resourceOrganization instanceof Organization
            ? $resourceOrganization
            : null;

        $organizationId = $organization?->id
            ?? (is_int($resourceOrganization) ? $resourceOrganization : $user->organization_id);

        if ((int) $organizationId !== (int) $user->organization_id) {
            return 'tenancy_mismatch';
        }

        if ($requireActiveOrganization) {
            $status = $organization?->status
                ?? Organization::query()->whereKey($organizationId)->value('status');

            $status = $status instanceof OrganizationStatus
                ? $status
                : OrganizationStatus::tryFrom((string) $status);

            if ($status === null) {
                return 'organization_missing';
            }

            if (! $this->statusPermits($status, $permission)) {
                return 'organization_status:'.$status->value;
            }
        }

        return null;
    }

    /**
     * A RESTRICTED member may still settle and view; it may not open new
     * exposure. A SUSPENDED, CLOSING or CLOSED member may only read.
     */
    private function statusPermits(OrganizationStatus $status, Permission $permission): bool
    {
        if ($status === OrganizationStatus::ACTIVE) {
            return true;
        }

        $readOnly = [
            Permission::ORDER_BOOK_VIEW,
            Permission::BALANCE_VIEW,
            Permission::LEDGER_VIEW,
            Permission::LOT_VIEW,
            Permission::AUDIT_VIEW,
        ];

        if (in_array($permission, $readOnly, true)) {
            return true;
        }

        $settlementOnly = [
            Permission::PAYMENT_CONFIRM_SENT,
            Permission::PAYMENT_CONFIRM_RECEIVED,
            Permission::SETTLEMENT_CONFIRM,
            Permission::NETTING_ACCEPT,
            Permission::DELIVERY_CONFIRM,
            Permission::ORDER_CANCEL_OWN,
            Permission::ORDER_CANCEL_ANY,
        ];

        if ($status->canSettle() && in_array($permission, $settlementOnly, true)) {
            return true;
        }

        // Administrative actions stay available while the member is still being
        // onboarded or is winding down.
        $administrative = [
            Permission::USER_MANAGE,
            Permission::USER_ROLE_CHANGE,
            Permission::KYC_EDIT,
            Permission::USER_LIMIT_SET,
            Permission::ORGANIZATION_CLOSE,
        ];

        return $status !== OrganizationStatus::CLOSED
            && in_array($permission, $administrative, true);
    }
}
