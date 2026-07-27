<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Named role. Organisation roles are scoped to one member; platform roles
 * belong to the operator's own staff and are never attached to a member.
 *
 * The permission sets below are the literal RBAC matrix of
 * docs/01-product/01-personas-roles.md §1.4. Cells marked "⚠️ مجاز با تأیید دوم"
 * still grant the permission — the second approval is enforced separately by
 * DualControlService, because a maker must be allowed to *make* the request.
 * Cells marked "👁 فقط مشاهده" grant only the corresponding *_VIEW permission.
 */
enum Role: string
{
    // --- organisation roles ------------------------------------------------
    case OWNER = 'OWNER';
    case MANAGER = 'MANAGER';
    case TRADER = 'TRADER';
    case ACCOUNTANT = 'ACCOUNTANT';
    case TREASURER = 'TREASURER';
    case OPERATOR = 'OPERATOR';
    case VIEWER = 'VIEWER';

    // --- platform roles ----------------------------------------------------
    case PLATFORM_ADMIN = 'PLATFORM_ADMIN';
    case COMPLIANCE_OFFICER = 'COMPLIANCE_OFFICER';
    case SETTLEMENT_OFFICER = 'SETTLEMENT_OFFICER';
    case VAULT_OFFICER = 'VAULT_OFFICER';
    case SUPPORT_AGENT = 'SUPPORT_AGENT';
    case AUDITOR = 'AUDITOR';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** @return list<self> */
    public static function organizationRoles(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $r): bool => ! $r->isPlatformRole(),
        ));
    }

    /** @return list<self> */
    public static function platformRoles(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $r): bool => $r->isPlatformRole(),
        ));
    }

    public function isPlatformRole(): bool
    {
        return in_array($this, [
            self::PLATFORM_ADMIN,
            self::COMPLIANCE_OFFICER,
            self::SETTLEMENT_OFFICER,
            self::VAULT_OFFICER,
            self::SUPPORT_AGENT,
            self::AUDITOR,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'مالک',
            self::MANAGER => 'مدیر',
            self::TRADER => 'معامله‌گر',
            self::ACCOUNTANT => 'حسابدار',
            self::TREASURER => 'خزانه‌دار',
            self::OPERATOR => 'اپراتور',
            self::VIEWER => 'مشاهده‌گر',
            self::PLATFORM_ADMIN => 'مدیر پلتفرم',
            self::COMPLIANCE_OFFICER => 'افسر انطباق',
            self::SETTLEMENT_OFFICER => 'افسر تسویه',
            self::VAULT_OFFICER => 'افسر خزانه',
            self::SUPPORT_AGENT => 'کارشناس پشتیبانی',
            self::AUDITOR => 'حسابرس',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::OWNER => [
                Permission::ORDER_BOOK_VIEW, Permission::ORDER_CREATE,
                Permission::ORDER_CANCEL_OWN, Permission::ORDER_CANCEL_ANY,
                Permission::RFQ_CREATE, Permission::RFQ_RESPOND,
                Permission::OTC_TRADE, Permission::TRADE_APPROVE_ABOVE_LIMIT,
                Permission::BALANCE_VIEW, Permission::LEDGER_VIEW,
                Permission::PAYMENT_CONFIRM_SENT, Permission::PAYMENT_CONFIRM_RECEIVED,
                Permission::WITHDRAWAL_REQUEST, Permission::NETTING_ACCEPT,
                Permission::BANK_ACCOUNT_MANAGE, Permission::SETTLEMENT_CONFIRM,
                Permission::LOT_VIEW, Permission::VAULT_DEPOSIT_REQUEST,
                Permission::VAULT_WITHDRAW, Permission::LOT_SPLIT_MERGE,
                Permission::ASSAY_RECORD, Permission::DELIVERY_CONFIRM,
                Permission::USER_MANAGE, Permission::USER_ROLE_CHANGE,
                Permission::KYC_EDIT, Permission::USER_LIMIT_SET,
                Permission::AUDIT_VIEW, Permission::ORGANIZATION_CLOSE,
            ],

            // Everything an OWNER has except ownership transfer, role changes
            // and closing the organisation.
            self::MANAGER => [
                Permission::ORDER_BOOK_VIEW, Permission::ORDER_CREATE,
                Permission::ORDER_CANCEL_OWN, Permission::ORDER_CANCEL_ANY,
                Permission::RFQ_CREATE, Permission::RFQ_RESPOND,
                Permission::OTC_TRADE, Permission::TRADE_APPROVE_ABOVE_LIMIT,
                Permission::BALANCE_VIEW, Permission::LEDGER_VIEW,
                Permission::PAYMENT_CONFIRM_SENT, Permission::PAYMENT_CONFIRM_RECEIVED,
                Permission::WITHDRAWAL_REQUEST, Permission::NETTING_ACCEPT,
                Permission::BANK_ACCOUNT_MANAGE, Permission::SETTLEMENT_CONFIRM,
                Permission::LOT_VIEW, Permission::VAULT_DEPOSIT_REQUEST,
                Permission::VAULT_WITHDRAW, Permission::LOT_SPLIT_MERGE,
                Permission::ASSAY_RECORD, Permission::DELIVERY_CONFIRM,
                Permission::USER_MANAGE, Permission::KYC_EDIT,
                Permission::USER_LIMIT_SET, Permission::AUDIT_VIEW,
            ],

            self::TRADER => [
                Permission::ORDER_BOOK_VIEW, Permission::ORDER_CREATE,
                Permission::ORDER_CANCEL_OWN, Permission::RFQ_CREATE,
                Permission::RFQ_RESPOND, Permission::OTC_TRADE,
                Permission::TRADE_APPROVE_ABOVE_LIMIT,
                Permission::BALANCE_VIEW, Permission::LEDGER_VIEW,
                Permission::LOT_VIEW,
            ],

            self::ACCOUNTANT => [
                Permission::ORDER_BOOK_VIEW,
                Permission::BALANCE_VIEW, Permission::LEDGER_VIEW,
                Permission::NETTING_ACCEPT,
                Permission::LOT_VIEW,
            ],

            self::TREASURER => [
                Permission::ORDER_BOOK_VIEW,
                Permission::BALANCE_VIEW, Permission::LEDGER_VIEW,
                Permission::PAYMENT_CONFIRM_SENT, Permission::PAYMENT_CONFIRM_RECEIVED,
                Permission::WITHDRAWAL_REQUEST, Permission::NETTING_ACCEPT,
                Permission::SETTLEMENT_CONFIRM,
                Permission::LOT_VIEW, Permission::VAULT_DEPOSIT_REQUEST,
                Permission::VAULT_WITHDRAW, Permission::LOT_SPLIT_MERGE,
                Permission::ASSAY_RECORD, Permission::DELIVERY_CONFIRM,
            ],

            self::OPERATOR => [
                Permission::ORDER_BOOK_VIEW, Permission::BALANCE_VIEW,
                Permission::LEDGER_VIEW, Permission::LOT_VIEW,
                Permission::VAULT_DEPOSIT_REQUEST, Permission::ASSAY_RECORD,
            ],

            self::VIEWER => [
                Permission::ORDER_BOOK_VIEW, Permission::BALANCE_VIEW,
                Permission::LEDGER_VIEW, Permission::LOT_VIEW,
            ],

            // --- platform ---------------------------------------------------
            self::PLATFORM_ADMIN => Permission::cases(),

            self::COMPLIANCE_OFFICER => [
                Permission::PLATFORM_KYC_REVIEW,
                Permission::PLATFORM_ORGANIZATION_SUSPEND,
                Permission::PLATFORM_ORGANIZATION_UNSUSPEND,
                Permission::PLATFORM_AML_MANAGE,
                Permission::PLATFORM_SUPPORT_VIEW,
                Permission::PLATFORM_AUDIT_VIEW,
            ],

            self::SETTLEMENT_OFFICER => [
                Permission::PLATFORM_SETTLEMENT_MANAGE,
                Permission::PLATFORM_LEDGER_ADJUST,
                Permission::PLATFORM_SUPPORT_VIEW,
            ],

            self::VAULT_OFFICER => [
                Permission::PLATFORM_VAULT_MANAGE,
                Permission::PLATFORM_SUPPORT_VIEW,
            ],

            self::SUPPORT_AGENT => [
                Permission::PLATFORM_SUPPORT_VIEW,
                Permission::PLATFORM_ORGANIZATION_SUSPEND,
            ],

            self::AUDITOR => [
                Permission::PLATFORM_AUDIT_VIEW,
                Permission::PLATFORM_SUPPORT_VIEW,
            ],
        };
    }

    public function grants(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
