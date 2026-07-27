<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Exceptions\DuplicateRegistrationException;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Domain\UserStatus;
use App\Modules\Identity\Domain\Validators\MobileNormalizer;
use App\Modules\Identity\Events\UserRegistered;
use App\Modules\Identity\Infrastructure\Models\Branch;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Adds a colleague to an existing member.
 *
 * The invitee is created in PENDING with a random, unusable password; they set
 * a real one when they accept the invitation. No password is ever transmitted.
 */
final class InviteUserService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly AssignRolesService $assignRoles,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  full_name, mobile, email, branch_id
     * @param  list<RoleEnum>  $roles
     * @return array{user: User, invitation_token: string}
     */
    public function invite(User $actor, array $attributes, array $roles): array
    {
        $organizationId = (int) $actor->organization_id;

        // Tenancy + permission, both explicit. The organisation need not be
        // ACTIVE — a member being onboarded still needs to add staff.
        $this->permissions->authorize($actor, Permission::USER_MANAGE, $organizationId);

        // Only a user who may change roles can hand out roles at invite time.
        if ($roles !== [] && $this->permissions->denies($actor, Permission::USER_ROLE_CHANGE, $organizationId)) {
            throw new OperationNotPermittedException('missing_permission:'.Permission::USER_ROLE_CHANGE->value);
        }

        foreach ($roles as $role) {
            if ($role === RoleEnum::OWNER && ! $actor->hasRole(RoleEnum::OWNER)) {
                throw new OperationNotPermittedException('only_owner_may_grant_owner');
            }
        }

        $mobile = MobileNormalizer::normalize((string) ($attributes['mobile'] ?? ''));

        if ($mobile === null) {
            throw new InvalidArgumentException('Invalid Iranian mobile number');
        }

        if (User::query()->where('mobile', $mobile)->exists()) {
            throw new DuplicateRegistrationException('mobile');
        }

        $branchId = $attributes['branch_id'] ?? null;
        if ($branchId !== null) {
            $branchOrganizationId = Branch::query()->whereKey($branchId)->value('organization_id');

            if ((int) $branchOrganizationId !== $organizationId) {
                throw new OperationNotPermittedException('branch_tenancy_mismatch');
            }
        }

        $invitationToken = Str::random(64);

        $user = DB::transaction(function () use ($attributes, $mobile, $organizationId, $branchId, $roles, $actor, $invitationToken): User {
            $user = new User;
            $user->fill([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'full_name' => (string) $attributes['full_name'],
                'mobile' => $mobile,
                'email' => $attributes['email'] ?? null,
                // Unusable until the invitation is accepted; hashing a random
                // string means a blank-password login can never succeed.
                'password_hash' => bcrypt(Str::random(48)),
                'status' => UserStatus::PENDING,
            ]);

            if (isset($attributes['national_id'])) {
                $user->setNationalId((string) $attributes['national_id']);
            }

            $user->save();

            DB::table('password_reset_tokens')->updateOrInsert(
                ['identifier' => $mobile],
                ['token' => hash('sha256', $invitationToken), 'created_at' => now()],
            );

            if ($roles !== []) {
                $this->assignRoles->grant($user, $roles, actorUserId: (int) $actor->id, silent: true);
            }

            return $user;
        });

        Event::dispatch(new UserRegistered(
            userId: (int) $user->id,
            organizationId: $organizationId,
            mobile: $mobile,
            fullName: (string) $user->full_name,
            invitedByUserId: (int) $actor->id,
            occurredAt: now()->toIso8601String(),
        ));

        return ['user' => $user, 'invitation_token' => $invitationToken];
    }

    /**
     * The invitee sets their password and the account becomes usable.
     */
    public function accept(string $mobile, string $token, string $password): User
    {
        $normalized = MobileNormalizer::normalize($mobile);

        if ($normalized === null) {
            throw new InvalidArgumentException('Invalid Iranian mobile number');
        }

        if (mb_strlen($password) < RegisterOrganizationService::minimumPasswordLength()) {
            throw new InvalidArgumentException(
                'Password must be at least '.RegisterOrganizationService::minimumPasswordLength().' characters'
            );
        }

        $row = DB::table('password_reset_tokens')->where('identifier', $normalized)->first();

        if ($row === null || ! hash_equals((string) $row->token, hash('sha256', $token))) {
            throw new OperationNotPermittedException('invalid_invitation_token');
        }

        return DB::transaction(function () use ($normalized, $password): User {
            /** @var User $user */
            $user = User::query()->where('mobile', $normalized)->lockForUpdate()->firstOrFail();

            $user->password_hash = bcrypt($password);
            $user->password_changed_at = now();
            $user->status = UserStatus::ACTIVE;
            $user->failed_login_count = 0;
            $user->locked_until = null;
            $user->save();

            DB::table('password_reset_tokens')->where('identifier', $normalized)->delete();

            return $user;
        });
    }
}
