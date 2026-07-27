<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Feature;

use App\Modules\Identity\Application\PermissionChecker;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Domain\UserStatus;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Tests\IdentityTestCase;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

/**
 * The three mandatory layers of docs/02-architecture/04-security.md §4.3.
 */
final class AuthorizationTest extends IdentityTestCase
{
    use RefreshDatabase;

    private PermissionChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->checker = $this->app->make(PermissionChecker::class);
    }

    public function test_permission_alone_is_not_enough_tenancy_is_checked_too(): void
    {
        $mine = Organization::factory()->active()->create();
        $theirs = Organization::factory()->active()->create();

        $trader = $this->makeUser($mine, [RoleEnum::TRADER]);

        $this->assertTrue($this->checker->allows($trader, Permission::ORDER_CREATE, $mine));

        // Same permission, someone else's organisation.
        $this->assertFalse($this->checker->allows($trader, Permission::ORDER_CREATE, $theirs));
        $this->assertSame(
            'tenancy_mismatch',
            $this->checker->denialReason($trader, Permission::ORDER_CREATE, $theirs),
        );
    }

    public function test_tenancy_alone_is_not_enough_permission_is_checked_too(): void
    {
        $organization = Organization::factory()->active()->create();
        $accountant = $this->makeUser($organization, [RoleEnum::ACCOUNTANT]);

        $this->assertTrue($this->checker->allows($accountant, Permission::LEDGER_VIEW, $organization));
        $this->assertFalse($this->checker->allows($accountant, Permission::ORDER_CREATE, $organization));
    }

    public function test_a_suspended_login_is_refused_regardless_of_role(): void
    {
        $organization = Organization::factory()->active()->create();
        $owner = $this->makeUser($organization, [RoleEnum::OWNER]);
        $owner->forceFill(['status' => UserStatus::SUSPENDED])->save();

        $this->assertSame(
            'user_not_active',
            $this->checker->denialReason($owner, Permission::ORDER_CREATE, $organization),
        );
    }

    public function test_organisation_status_gates_new_exposure_but_not_settlement(): void
    {
        $organization = Organization::factory()->status(OrganizationStatus::RESTRICTED)->create();
        $owner = $this->makeUser($organization, [RoleEnum::OWNER]);

        // RESTRICTED: no new orders...
        $this->assertFalse($this->checker->allows($owner, Permission::ORDER_CREATE, $organization));
        // ...but reducing exposure and settling stay open.
        $this->assertTrue($this->checker->allows($owner, Permission::ORDER_CANCEL_OWN, $organization));
        $this->assertTrue($this->checker->allows($owner, Permission::SETTLEMENT_CONFIRM, $organization));
        $this->assertTrue($this->checker->allows($owner, Permission::BALANCE_VIEW, $organization));
    }

    public function test_a_suspended_organisation_is_read_only(): void
    {
        $organization = Organization::factory()->status(OrganizationStatus::SUSPENDED)->create();
        $owner = $this->makeUser($organization, [RoleEnum::OWNER]);

        $this->assertFalse($this->checker->allows($owner, Permission::ORDER_CREATE, $organization));
        $this->assertFalse($this->checker->allows($owner, Permission::VAULT_WITHDRAW, $organization));
        $this->assertTrue($this->checker->allows($owner, Permission::LEDGER_VIEW, $organization));
    }

    public function test_platform_permissions_require_platform_staff(): void
    {
        $member = Organization::factory()->active()->create();
        $owner = $this->makeUser($member, [RoleEnum::OWNER]);

        // An OWNER holds no platform permission at all.
        $this->assertFalse($owner->hasPermission(Permission::PLATFORM_KYC_REVIEW));
        $this->assertFalse($this->checker->allows($owner, Permission::PLATFORM_KYC_REVIEW));

        $platform = Organization::factory()->platform()->create();
        $officer = $this->makeUser($platform, [RoleEnum::COMPLIANCE_OFFICER]);

        $this->assertTrue($this->checker->allows($officer, Permission::PLATFORM_KYC_REVIEW));
        // Platform staff act across tenants by design.
        $this->assertTrue($this->checker->allows($officer, Permission::PLATFORM_KYC_REVIEW, $member));
    }

    public function test_authorize_throws_with_a_machine_readable_reason(): void
    {
        $organization = Organization::factory()->active()->create();
        $viewer = $this->makeUser($organization, [RoleEnum::VIEWER]);

        try {
            $this->checker->authorize($viewer, Permission::ORDER_CREATE, $organization);
            $this->fail('a VIEWER must not be able to create orders');
        } catch (OperationNotPermittedException $e) {
            $this->assertSame('missing_permission:order.create', $e->reason);
            $this->assertSame(403, $e->httpStatus());
        }
    }

    public function test_gates_are_registered_for_every_permission(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertTrue(
                Gate::has($permission->value),
                "no gate registered for {$permission->value}",
            );
        }
    }

    public function test_gate_enforces_tenancy_and_permission_together(): void
    {
        $mine = Organization::factory()->active()->create();
        $theirs = Organization::factory()->active()->create();
        $trader = $this->makeUser($mine, [RoleEnum::TRADER]);

        $this->assertTrue(Gate::forUser($trader)->allows('order.create', $mine));
        $this->assertFalse(Gate::forUser($trader)->allows('order.create', $theirs));
        $this->assertFalse(Gate::forUser($trader)->allows('user.role.change', $mine));
    }

    public function test_the_rbac_matrix_is_wired_into_the_role_enum(): void
    {
        // Spot-checks straight off docs/01-product/01-personas-roles.md §1.4.
        $this->assertTrue(RoleEnum::OWNER->grants(Permission::ORGANIZATION_CLOSE));
        $this->assertFalse(RoleEnum::MANAGER->grants(Permission::ORGANIZATION_CLOSE));
        $this->assertFalse(RoleEnum::MANAGER->grants(Permission::USER_ROLE_CHANGE));

        $this->assertTrue(RoleEnum::TRADER->grants(Permission::ORDER_CANCEL_OWN));
        $this->assertFalse(RoleEnum::TRADER->grants(Permission::ORDER_CANCEL_ANY));

        $this->assertFalse(RoleEnum::ACCOUNTANT->grants(Permission::ORDER_CREATE));
        $this->assertTrue(RoleEnum::ACCOUNTANT->grants(Permission::NETTING_ACCEPT));

        $this->assertTrue(RoleEnum::TREASURER->grants(Permission::VAULT_WITHDRAW));
        $this->assertFalse(RoleEnum::TREASURER->grants(Permission::ORDER_CREATE));

        $this->assertFalse(RoleEnum::VIEWER->grants(Permission::ORDER_CREATE));
        $this->assertTrue(RoleEnum::VIEWER->grants(Permission::LEDGER_VIEW));
    }

    public function test_seeder_materialises_the_matrix_into_the_tables(): void
    {
        $this->assertDatabaseCount('roles', count(RoleEnum::cases()));
        $this->assertDatabaseCount('permissions', count(Permission::cases()));

        $this->assertDatabaseHas('permissions', [
            'name' => Permission::VAULT_WITHDRAW->value,
            'requires_dual_control' => true,
        ]);

        $ownerGrants = Role::query()
            ->where('name', RoleEnum::OWNER->value)
            ->firstOrFail()
            ->permissions()
            ->count();

        $this->assertSame(count(RoleEnum::OWNER->permissions()), $ownerGrants);

        // Member roles never carry platform permissions, even in the tables.
        $traderPlatformGrants = Role::query()
            ->where('name', RoleEnum::TRADER->value)
            ->firstOrFail()
            ->permissions()
            ->where('name', 'like', 'platform.%')
            ->count();

        $this->assertSame(0, $traderPlatformGrants);
    }
}
