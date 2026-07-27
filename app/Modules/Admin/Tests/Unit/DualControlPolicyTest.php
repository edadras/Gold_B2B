<?php

declare(strict_types=1);

namespace App\Modules\Admin\Tests\Unit;

use App\Modules\Admin\Domain\DualControlPolicy;
use App\Modules\Identity\Contracts\UserSnapshot;
use App\Modules\Identity\Domain\Role;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The maker-checker rule as pure logic — no database, no HTTP.
 *
 * docs/01-product/01-personas-roles.md §1.6: «اصلاح دستی دفتر کل (پلتفرم)»
 * maker SETTLEMENT_OFFICER, checker PLATFORM_ADMIN, and the hard rule
 * `maker_user_id != checker_user_id`.
 */
final class DualControlPolicyTest extends TestCase
{
    #[Test]
    public function only_a_settlement_officer_may_raise_a_request(): void
    {
        $policy = new DualControlPolicy;

        self::assertTrue($policy->mayMake($this->user(1, [Role::SETTLEMENT_OFFICER])));
        self::assertFalse($policy->mayMake($this->user(2, [Role::PLATFORM_ADMIN])));
        self::assertFalse($policy->mayMake($this->user(3, [Role::SUPPORT_AGENT])));
        self::assertFalse($policy->mayMake($this->user(4, [Role::OWNER])));
    }

    #[Test]
    public function only_a_platform_admin_may_approve(): void
    {
        $policy = new DualControlPolicy;

        self::assertTrue($policy->mayCheck($this->user(1, [Role::PLATFORM_ADMIN])));
        self::assertFalse($policy->mayCheck($this->user(2, [Role::SETTLEMENT_OFFICER])));
        self::assertFalse($policy->mayCheck($this->user(3, [Role::AUDITOR])));
    }

    #[Test]
    public function maker_and_checker_must_be_different_people(): void
    {
        $policy = new DualControlPolicy;

        self::assertFalse($policy->areDistinctPeople(7, 7));
        self::assertTrue($policy->areDistinctPeople(7, 8));
    }

    #[Test]
    public function the_checker_must_bring_a_role_the_maker_did_not_hold(): void
    {
        $policy = new DualControlPolicy;

        $maker = $this->user(1, [Role::SETTLEMENT_OFFICER]);
        $checker = $this->user(2, [Role::PLATFORM_ADMIN]);

        self::assertTrue($policy->bringsADifferentRole($maker, $checker));

        // A maker who already wears the checker's hat gets no second opinion
        // from someone wearing the same one.
        $dualRoleMaker = $this->user(3, [Role::SETTLEMENT_OFFICER, Role::PLATFORM_ADMIN]);

        self::assertFalse($policy->bringsADifferentRole($dualRoleMaker, $checker));
    }

    /** @param list<Role> $roles */
    private function user(int $id, array $roles): UserSnapshot
    {
        return new UserSnapshot(
            id: $id,
            organizationId: 1,
            branchId: null,
            fullName: 'کاربر آزمون',
            status: 'ACTIVE',
            roles: array_map(static fn (Role $r): string => $r->value, $roles),
            permissions: [],
        );
    }
}
