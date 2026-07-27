<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

use App\Modules\Identity\Domain\Role;

/**
 * Role-based targeting (§15.4 rule 5).
 *
 * The doc pins four cases explicitly — PAYMENT_REQUIRED to TREASURER and OWNER,
 * ORDER_FILLED to TRADER and OWNER, KYC_* to OWNER alone, DISPUTE_* to OWNER and
 * MANAGER — and leaves the rest to sensible defaults, which are provided per
 * category below.
 *
 * VIEWER appears nowhere on purpose. A read-only account can see the
 * organisation's feed, but a notification is a call to act, and pushing one to
 * somebody with no authority to act on it trains the whole organisation to
 * ignore notifications.
 */
final class RoleTargeting
{
    /**
     * The roles that receive a given code.
     *
     * @return list<Role>
     */
    public static function rolesFor(NotificationCode $code): array
    {
        return match ($code) {
            // --- the four the doc pins down ---------------------------------
            NotificationCode::PAYMENT_REQUIRED => [Role::TREASURER, Role::OWNER],
            NotificationCode::ORDER_FILLED => [Role::TRADER, Role::OWNER],

            NotificationCode::KYC_APPROVED,
            NotificationCode::KYC_INFO_REQUIRED,
            NotificationCode::KYC_REJECTED => [Role::OWNER],

            NotificationCode::DISPUTE_OPENED_AGAINST,
            NotificationCode::DISPUTE_REPLY_DUE,
            NotificationCode::DISPUTE_MESSAGE,
            NotificationCode::DISPUTE_RESOLVED => [Role::OWNER, Role::MANAGER],

            // --- money leaving or arriving ----------------------------------
            NotificationCode::PAYMENT_DECLARED,
            NotificationCode::PAYMENT_CONFIRMED,
            NotificationCode::NETTING_PROPOSED,
            NotificationCode::VAULT_WITHDRAWAL_APPROVED => [Role::TREASURER, Role::OWNER, Role::MANAGER],

            // --- account-level facts, for whoever owns the organisation ------
            NotificationCode::ACCOUNT_RESTRICTED,
            NotificationCode::LICENSE_EXPIRING_30,
            NotificationCode::LICENSE_EXPIRING_10,
            NotificationCode::LICENSE_EXPIRED,
            NotificationCode::NEW_LOGIN,
            NotificationCode::LIMIT_INCREASED,
            NotificationCode::TIER_UPGRADED => [Role::OWNER, Role::MANAGER],

            default => self::defaultsFor($code->category()),
        };
    }

    /** @return list<Role> */
    public static function defaultsFor(Category $category): array
    {
        return match ($category) {
            Category::TRADING => [Role::TRADER, Role::OWNER, Role::MANAGER],
            Category::SETTLEMENT => [Role::TREASURER, Role::ACCOUNTANT, Role::OWNER, Role::MANAGER],
            Category::ASSET => [Role::OPERATOR, Role::TREASURER, Role::OWNER, Role::MANAGER],
            Category::ACCOUNT => [Role::OWNER],
            Category::DISPUTE => [Role::OWNER, Role::MANAGER],
        };
    }

    /** @return list<string> */
    public static function roleNamesFor(NotificationCode $code): array
    {
        return array_map(static fn (Role $r): string => $r->value, self::rolesFor($code));
    }

    /** @param  list<string>  $roles */
    public static function targets(NotificationCode $code, array $roles): bool
    {
        return array_intersect(self::roleNamesFor($code), $roles) !== [];
    }
}
