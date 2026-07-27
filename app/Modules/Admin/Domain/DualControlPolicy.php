<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain;

use App\Modules\Identity\Contracts\UserSnapshot;
use App\Modules\Identity\Domain\Role;

/**
 * Maker-checker for the manual ledger adjustment
 * (docs/01-product/01-personas-roles.md §1.6 — «اصلاح دستی دفتر کل (پلتفرم)»:
 * maker SETTLEMENT_OFFICER, checker PLATFORM_ADMIN).
 *
 * Three separate refusals, deliberately not collapsed into one:
 *   1. the maker must hold a maker role;
 *   2. the checker must hold a checker role;
 *   3. maker and checker must be different people AND must not be acting under
 *      the same role — a user who happens to hold both roles cannot approve
 *      their own department's work by wearing the other hat.
 *
 * Pure functions over UserSnapshot so this is unit-testable without a database.
 */
final class DualControlPolicy
{
    /**
     * Roles that may *raise* a manual ledger adjustment request.
     *
     * SETTLEMENT_OFFICER only, exactly as §1.10's `canAccess()`. Admitting
     * PLATFORM_ADMIN here would leave the checker with no role the maker did
     * not already hold, which is the same as having no second pair of eyes.
     */
    public const MAKER_ROLES = [Role::SETTLEMENT_OFFICER];

    /** Roles that may *approve* one. */
    public const CHECKER_ROLES = [Role::PLATFORM_ADMIN];

    public function mayMake(UserSnapshot $user): bool
    {
        return $this->holdsAny($user, self::MAKER_ROLES);
    }

    public function mayCheck(UserSnapshot $user): bool
    {
        return $this->holdsAny($user, self::CHECKER_ROLES);
    }

    /**
     * The rule the database constraint also encodes: `maker != checker`.
     *
     * Compared on id, never on anything derived from the request, so a forged
     * form field cannot make one person look like two.
     */
    public function areDistinctPeople(int $makerUserId, int $checkerUserId): bool
    {
        return $makerUserId !== $checkerUserId;
    }

    /**
     * True when the checker brings a role the maker did not act under.
     *
     * "A second user with a different role" is stronger than "a second user":
     * two settlement officers approving each other reintroduces exactly the
     * single-department failure mode dual control exists to prevent.
     */
    public function bringsADifferentRole(UserSnapshot $maker, UserSnapshot $checker): bool
    {
        $checkerRoles = array_intersect(
            $checker->roles,
            array_map(static fn (Role $r): string => $r->value, self::CHECKER_ROLES),
        );

        return array_diff($checkerRoles, $maker->roles) !== [];
    }

    /**
     * @param  list<Role>  $roles
     */
    private function holdsAny(UserSnapshot $user, array $roles): bool
    {
        foreach ($roles as $role) {
            if (in_array($role->value, $user->roles, true)) {
                return true;
            }
        }

        return false;
    }
}
