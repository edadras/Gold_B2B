<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Feature;

use App\Modules\Identity\Application\AssignRolesService;
use App\Modules\Identity\Application\InviteUserService;
use App\Modules\Identity\Application\RegisterOrganizationService;
use App\Modules\Identity\Domain\Exceptions\DuplicateRegistrationException;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Domain\OrganizationType;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Domain\UserStatus;
use App\Modules\Identity\Events\OrganizationCreated;
use App\Modules\Identity\Events\UserRegistered;
use App\Modules\Identity\Events\UserRoleChanged;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Tests\IdentityTestCase;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

final class RegistrationAndRolesTest extends IdentityTestCase
{
    use RefreshDatabase;

    private RegisterOrganizationService $register;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->register = $this->app->make(RegisterOrganizationService::class);
    }

    public function test_registration_creates_a_pending_organisation_with_an_owner(): void
    {
        Event::fake();

        $result = $this->register->register(
            [
                'type' => OrganizationType::INDIVIDUAL,
                'display_name' => 'طلافروشی کریمی',
                'city' => 'تهران',
                'mobile' => '09121234567',
                'national_id' => '0012345679',
                'union_name' => 'اتحادیه طلا و جواهر',
            ],
            [
                'full_name' => 'علی کریمی',
                'mobile' => '۰۹۱۲۳۳۳۴۴۵۵',
                'password' => 'a-sufficiently-long-password',
            ],
        );

        $organization = $result['organization'];
        $owner = $result['owner'];

        $this->assertSame(OrganizationStatus::PENDING, $organization->status);
        $this->assertSame('989121234567', $organization->mobile);
        // Persian digits in the owner's mobile normalise on the way in.
        $this->assertSame('989123334455', $owner->mobile);
        $this->assertSame(UserStatus::ACTIVE, $owner->status);
        $this->assertTrue($owner->hasRole(RoleEnum::OWNER));
        $this->assertTrue($owner->hasPermission(Permission::ORDER_CREATE));

        // The blind index, not the plaintext, is what makes the row findable.
        $this->assertNotNull($organization->national_id_hash);
        $this->assertSame(
            (int) $organization->id,
            (int) Organization::query()->withNationalId('0012345679')->firstOrFail()->id,
        );

        // The initial state is recorded like every other transition.
        $this->assertDatabaseHas('organization_status_events', [
            'organization_id' => $organization->id,
            'from_status' => null,
            'to_status' => OrganizationStatus::PENDING->value,
        ]);

        Event::assertDispatched(OrganizationCreated::class);
        Event::assertDispatched(UserRegistered::class);
    }

    public function test_registration_rejects_a_duplicate_national_id(): void
    {
        Event::fake();

        $attributes = [
            'type' => OrganizationType::INDIVIDUAL,
            'display_name' => 'طلافروشی الف',
            'city' => 'تهران',
            'mobile' => '09121234567',
            'national_id' => '0012345679',
        ];

        $this->register->register($attributes, [
            'full_name' => 'الف',
            'mobile' => '09123334455',
            'password' => 'a-sufficiently-long-password',
        ]);

        $attributes['mobile'] = '09121234568';

        try {
            $this->register->register($attributes, [
                'full_name' => 'ب',
                'mobile' => '09123334456',
                'password' => 'a-sufficiently-long-password',
            ]);
            $this->fail('a second registration with the same national id must be refused');
        } catch (DuplicateRegistrationException $e) {
            $this->assertSame('national_id', $e->field);
            $this->assertSame(409, $e->httpStatus());
        }
    }

    public function test_registration_validates_identity_numbers_and_password_length(): void
    {
        Event::fake();

        $base = [
            'type' => OrganizationType::INDIVIDUAL,
            'display_name' => 'طلافروشی',
            'city' => 'تهران',
            'mobile' => '09121234567',
        ];
        $owner = [
            'full_name' => 'الف',
            'mobile' => '09123334455',
            'password' => 'a-sufficiently-long-password',
        ];

        $this->assertThrows(
            fn () => $this->register->register($base + ['national_id' => '0012345678'], $owner),
            InvalidArgumentException::class,
        );

        // An individual member must supply a national id at all.
        $this->assertThrows(
            fn () => $this->register->register($base, $owner),
            InvalidArgumentException::class,
        );

        // A legal entity must supply a legal id.
        $this->assertThrows(
            fn () => $this->register->register(
                ['type' => OrganizationType::LEGAL_ENTITY] + $base,
                $owner,
            ),
            InvalidArgumentException::class,
        );

        $this->assertThrows(
            fn () => $this->register->register(
                $base + ['national_id' => '0012345679'],
                ['password' => 'short'] + $owner,
            ),
            InvalidArgumentException::class,
        );
    }

    public function test_owner_can_invite_a_trader_who_then_sets_a_password(): void
    {
        Event::fake();

        $result = $this->register->register(
            [
                'type' => OrganizationType::INDIVIDUAL,
                'display_name' => 'طلافروشی کریمی',
                'city' => 'تهران',
                'mobile' => '09121234567',
                'national_id' => '0012345679',
            ],
            [
                'full_name' => 'علی کریمی',
                'mobile' => '09123334455',
                'password' => 'a-sufficiently-long-password',
            ],
        );

        $invites = $this->app->make(InviteUserService::class);

        $invitation = $invites->invite(
            $result['owner'],
            ['full_name' => 'حسن رضایی', 'mobile' => '09351112233'],
            [RoleEnum::TRADER],
        );

        $invited = $invitation['user'];

        $this->assertSame(UserStatus::PENDING, $invited->status);
        $this->assertSame((int) $result['organization']->id, (int) $invited->organization_id);
        $this->assertTrue($invited->hasRole(RoleEnum::TRADER));

        $accepted = $invites->accept('09351112233', $invitation['invitation_token'], 'another-long-password');

        $this->assertSame(UserStatus::ACTIVE, $accepted->status);
        $this->assertDatabaseMissing('password_reset_tokens', ['identifier' => '989351112233']);
    }

    public function test_a_trader_cannot_invite_anyone(): void
    {
        Event::fake();

        $organization = Organization::factory()->active()->create();
        $trader = $this->makeUser($organization, [RoleEnum::TRADER]);

        $this->expectException(OperationNotPermittedException::class);

        $this->app->make(InviteUserService::class)->invite(
            $trader,
            ['full_name' => 'کسی', 'mobile' => '09351112244'],
            [],
        );
    }

    public function test_role_changes_emit_an_event_with_the_before_and_after_sets(): void
    {
        Event::fake();

        $organization = Organization::factory()->active()->create();
        $this->makeUser($organization, [RoleEnum::OWNER]);
        $user = $this->makeUser($organization, [RoleEnum::VIEWER]);

        $this->app->make(AssignRolesService::class)->sync($user, [RoleEnum::TRADER, RoleEnum::ACCOUNTANT]);

        $this->assertTrue($user->hasRole(RoleEnum::TRADER));
        $this->assertFalse($user->hasRole(RoleEnum::VIEWER));

        Event::assertDispatched(
            UserRoleChanged::class,
            function (UserRoleChanged $e) use ($user): bool {
                $current = $e->currentRoles;
                sort($current);

                return $e->userId === (int) $user->id
                    && $e->previousRoles === ['VIEWER']
                    && $current === ['ACCOUNTANT', 'TRADER'];
            },
        );
    }

    public function test_an_organisation_must_always_keep_an_owner(): void
    {
        Event::fake();

        $organization = Organization::factory()->active()->create();
        $owner = $this->makeUser($organization, [RoleEnum::OWNER]);

        $this->expectException(OperationNotPermittedException::class);

        $this->app->make(AssignRolesService::class)->revoke($owner, [RoleEnum::OWNER]);
    }

    public function test_platform_roles_cannot_be_granted_inside_a_member_organisation(): void
    {
        Event::fake();

        $organization = Organization::factory()->active()->create();
        $this->makeUser($organization, [RoleEnum::OWNER]);
        $user = $this->makeUser($organization, [RoleEnum::VIEWER]);

        try {
            $this->app->make(AssignRolesService::class)->grant($user, [RoleEnum::COMPLIANCE_OFFICER]);
            $this->fail('a member user must not receive a platform role');
        } catch (OperationNotPermittedException $e) {
            $this->assertStringContainsString('platform_role', $e->reason);
        }
    }

    public function test_platform_roles_are_grantable_inside_the_platform_organisation(): void
    {
        Event::fake();

        $platform = Organization::factory()->platform()->create();
        $officer = $this->makeUser($platform);

        $this->app->make(AssignRolesService::class)->grant($officer, [RoleEnum::COMPLIANCE_OFFICER]);

        $this->assertTrue($officer->hasRole(RoleEnum::COMPLIANCE_OFFICER));
        $this->assertTrue($officer->isPlatformStaff());
        $this->assertTrue($officer->hasPermission(Permission::PLATFORM_KYC_REVIEW));
    }
}
