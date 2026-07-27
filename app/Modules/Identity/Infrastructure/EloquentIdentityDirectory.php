<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\PermissionChecker;
use App\Modules\Identity\Contracts\IdentityDirectory;
use App\Modules\Identity\Contracts\OrganizationIdentityChecks;
use App\Modules\Identity\Contracts\OrganizationSnapshot;
use App\Modules\Identity\Contracts\UserSnapshot;
use App\Modules\Identity\Domain\AuthorityType;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Domain\Validators\LegalIdValidator;
use App\Modules\Identity\Domain\Validators\NationalIdValidator;
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
            registrationNo: $organization->registration_no,
            // Presence only — never the identifier itself, never its blind index.
            hasNationalId: $organization->national_id_hash !== null,
            hasLegalId: $organization->legal_id_hash !== null,
            isPlatform: (bool) $organization->is_platform,
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

    public function organizationIdentityChecks(int $organizationId): ?OrganizationIdentityChecks
    {
        $organization = Organization::query()->find($organizationId);

        if ($organization === null) {
            return null;
        }

        // Decryption happens here and nowhere else: this class lives inside
        // Identity, so it may see the plaintext. Only the verdicts leave.
        $nationalId = (string) ($organization->national_id_enc ?? '');
        $legalId = (string) ($organization->legal_id_enc ?? '');

        return new OrganizationIdentityChecks(
            nationalIdValid: $nationalId === '' ? null : NationalIdValidator::isValid($nationalId),
            legalIdValid: $legalId === '' ? null : LegalIdValidator::isValid($legalId),
            duplicateNationalId: $this->hashUsedElsewhere(
                'national_id_hash',
                $organization->national_id_hash,
                $organizationId,
            ),
            duplicateLegalId: $this->hashUsedElsewhere(
                'legal_id_hash',
                $organization->legal_id_hash,
                $organizationId,
            ),
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

    /**
     * "عدم ثبت‌نام تکراری با همین کد ملی" — the same identifier registered to a
     * second member. Answered against the blind index, so no row is decrypted.
     */
    private function hashUsedElsewhere(string $column, ?string $hash, int $organizationId): bool
    {
        if ($hash === null) {
            return false;
        }

        return Organization::query()
            ->where($column, $hash)
            ->whereKeyNot($organizationId)
            ->exists();
    }
}
