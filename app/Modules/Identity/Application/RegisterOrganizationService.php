<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\BlindIndex;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Domain\OrganizationType;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Domain\UserStatus;
use App\Modules\Identity\Domain\Validators\LegalIdValidator;
use App\Modules\Identity\Domain\Validators\MobileNormalizer;
use App\Modules\Identity\Domain\Validators\NationalIdValidator;
use App\Modules\Identity\Events\OrganizationCreated;
use App\Modules\Identity\Events\UserRegistered;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * Self-service registration: creates the organisation in PENDING together with
 * its first user, who becomes OWNER.
 *
 * Nothing here approves anything — the member cannot trade until Compliance
 * moves it through UNDER_REVIEW → VERIFIED → ACTIVE.
 */
final class RegisterOrganizationService
{
    public function __construct(
        private readonly OrganizationStateMachine $stateMachine,
        private readonly AssignRolesService $assignRoles,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  organisation fields
     * @param  array<string, mixed>  $ownerAttributes  full_name, mobile, password, email, national_id
     * @return array{organization: Organization, owner: User}
     */
    public function register(array $attributes, array $ownerAttributes): array
    {
        $type = $attributes['type'] instanceof OrganizationType
            ? $attributes['type']
            : OrganizationType::from((string) $attributes['type']);

        $organizationMobile = $this->requireMobile((string) ($attributes['mobile'] ?? ''), 'organization.mobile');
        $ownerMobile = $this->requireMobile((string) ($ownerAttributes['mobile'] ?? ''), 'owner.mobile');

        $nationalId = $this->normalizeNationalId($attributes['national_id'] ?? null, $type);
        $legalId = $this->normalizeLegalId($attributes['legal_id'] ?? null, $type);

        $this->assertNotAlreadyRegistered($nationalId, $legalId, $organizationMobile, $ownerMobile);

        $password = (string) ($ownerAttributes['password'] ?? '');
        $this->assertPasswordAcceptable($password);

        /** @var array{organization: Organization, owner: User} $result */
        $result = DB::transaction(function () use (
            $attributes, $ownerAttributes, $type, $organizationMobile, $ownerMobile,
            $nationalId, $legalId, $password
        ): array {
            $organization = new Organization;
            $organization->fill([
                'type' => $type,
                'status' => OrganizationStatus::PENDING,
                'display_name' => (string) $attributes['display_name'],
                'legal_name' => $attributes['legal_name'] ?? null,
                'registration_no' => $attributes['registration_no'] ?? null,
                'established_at' => $attributes['established_at'] ?? null,
                'union_name' => $attributes['union_name'] ?? null,
                'city' => (string) $attributes['city'],
                'province' => $attributes['province'] ?? null,
                'market_name' => $attributes['market_name'] ?? null,
                'address' => $attributes['address'] ?? null,
                'postal_code' => $attributes['postal_code'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'mobile' => $organizationMobile,
                'email' => $attributes['email'] ?? null,
                'website' => $attributes['website'] ?? null,
            ]);
            $organization->setNationalId($nationalId);
            $organization->setLegalId($legalId);
            $organization->save();

            $this->stateMachine->recordInitialState($organization);

            $owner = new User;
            $owner->fill([
                'organization_id' => $organization->id,
                'full_name' => (string) $ownerAttributes['full_name'],
                'mobile' => $ownerMobile,
                'email' => $ownerAttributes['email'] ?? null,
                'password_hash' => Hash::make($password),
                'password_changed_at' => now(),
                // The owner may sign in immediately; the *organisation* is what
                // is gated, not the login.
                'status' => UserStatus::ACTIVE,
            ]);
            $owner->setNationalId(
                isset($ownerAttributes['national_id']) ? (string) $ownerAttributes['national_id'] : $nationalId
            );
            $owner->save();

            $this->assignRoles->grant($owner, [RoleEnum::OWNER], actorUserId: null, silent: true);

            return ['organization' => $organization, 'owner' => $owner];
        });

        // Events after commit (AGENT_BRIEF rule 3).
        $occurredAt = now()->toIso8601String();

        Event::dispatch(new OrganizationCreated(
            organizationId: (int) $result['organization']->id,
            type: $type->value,
            displayName: (string) $result['organization']->display_name,
            city: (string) $result['organization']->city,
            occurredAt: $occurredAt,
        ));

        Event::dispatch(new UserRegistered(
            userId: (int) $result['owner']->id,
            organizationId: (int) $result['organization']->id,
            mobile: $ownerMobile,
            fullName: (string) $result['owner']->full_name,
            invitedByUserId: null,
            occurredAt: $occurredAt,
        ));

        return $result;
    }

    /** Member declares its paperwork complete: PENDING → UNDER_REVIEW. */
    public function submitForReview(Organization $organization, ?int $actorUserId = null): Organization
    {
        return $this->stateMachine->transition(
            organization: $organization,
            target: OrganizationStatus::UNDER_REVIEW,
            actorUserId: $actorUserId,
            reason: 'ارسال مدارک برای بررسی',
        );
    }

    private function requireMobile(string $raw, string $field): string
    {
        $normalized = MobileNormalizer::normalize($raw);

        if ($normalized === null) {
            throw new InvalidArgumentException("Invalid Iranian mobile number for {$field}");
        }

        return $normalized;
    }

    private function normalizeNationalId(mixed $value, OrganizationType $type): ?string
    {
        if ($value === null || $value === '') {
            if ($type->requiresNationalId()) {
                throw new InvalidArgumentException('An individual member requires a national id');
            }

            return null;
        }

        $raw = (string) $value;

        if (! NationalIdValidator::isValid($raw)) {
            throw new InvalidArgumentException('Invalid Iranian national id');
        }

        return NationalIdValidator::normalize($raw);
    }

    private function normalizeLegalId(mixed $value, OrganizationType $type): ?string
    {
        if ($value === null || $value === '') {
            if ($type->requiresLegalId()) {
                throw new InvalidArgumentException('A legal entity requires a legal id (شناسه ملی)');
            }

            return null;
        }

        $raw = (string) $value;

        if (! LegalIdValidator::isValid($raw)) {
            throw new InvalidArgumentException('Invalid Iranian legal entity id');
        }

        return LegalIdValidator::normalize($raw);
    }

    /**
     * "عدم ثبت‌نام تکراری با همین کد ملی" — one of the automated checks the
     * compliance officer sees (docs §1.5). Caught here so it surfaces as a
     * domain error rather than a unique-key violation.
     */
    private function assertNotAlreadyRegistered(
        ?string $nationalId,
        ?string $legalId,
        string $organizationMobile,
        string $ownerMobile,
    ): void {
        if ($nationalId !== null
            && Organization::query()->where('national_id_hash', BlindIndex::forNationalId($nationalId))->exists()) {
            throw new DuplicateRegistrationException('national_id');
        }

        if ($legalId !== null
            && Organization::query()->where('legal_id_hash', BlindIndex::forLegalId($legalId))->exists()) {
            throw new DuplicateRegistrationException('legal_id');
        }

        if (Organization::query()->where('mobile', $organizationMobile)->exists()) {
            throw new DuplicateRegistrationException('organization_mobile');
        }

        if (User::query()->where('mobile', $ownerMobile)->exists()) {
            throw new DuplicateRegistrationException('owner_mobile');
        }
    }

    /** docs/02-architecture/04-security.md §4.2 — minimum 12 characters. */
    private function assertPasswordAcceptable(string $password): void
    {
        if (mb_strlen($password) < self::minimumPasswordLength()) {
            throw new InvalidArgumentException(
                'Password must be at least '.self::minimumPasswordLength().' characters'
            );
        }
    }

    public static function minimumPasswordLength(): int
    {
        return 12;
    }
}

/**
 * Registration collided with an existing member.
 *
 * Declared alongside the service because it has no meaning outside it; the
 * shared exception hierarchy in Shared/Exceptions covers cross-module cases.
 */
final class DuplicateRegistrationException extends DomainException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct('DUPLICATE_REGISTRATION');
    }

    public function errorCode(): string
    {
        return 'DUPLICATE_REGISTRATION';
    }

    public function userMessage(): string
    {
        return 'با این مشخصات قبلاً ثبت‌نام انجام شده است.';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['field' => $this->field];
    }
}
