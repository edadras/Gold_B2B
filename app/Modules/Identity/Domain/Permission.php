<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Every authorisable action in the platform, as a named constant rather than a
 * string scattered through the code (docs/01-product/01-personas-roles.md §1.7).
 *
 * The dotted string value is what lands in the `permissions` table and in the
 * Gate ability name, so these values are part of the data contract: renaming
 * one requires a migration.
 */
enum Permission: string
{
    // --- الف) معاملات — trading -------------------------------------------
    case ORDER_BOOK_VIEW = 'orderbook.view';
    case ORDER_CREATE = 'order.create';
    case ORDER_CANCEL_OWN = 'order.cancel.own';
    case ORDER_CANCEL_ANY = 'order.cancel.any';
    case RFQ_CREATE = 'rfq.create';
    case RFQ_RESPOND = 'rfq.respond';
    case OTC_TRADE = 'otc.trade';
    case TRADE_APPROVE_ABOVE_LIMIT = 'trade.approve.above_limit';

    // --- ب) مالی و تسویه — money and settlement ---------------------------
    case BALANCE_VIEW = 'balance.view';
    case LEDGER_VIEW = 'ledger.view';
    case PAYMENT_CONFIRM_SENT = 'payment.confirm.sent';
    case PAYMENT_CONFIRM_RECEIVED = 'payment.confirm.received';
    case WITHDRAWAL_REQUEST = 'withdrawal.request';
    case NETTING_ACCEPT = 'netting.accept';
    case BANK_ACCOUNT_MANAGE = 'bank_account.manage';
    case SETTLEMENT_CONFIRM = 'settlement.confirm';

    // --- ج) طلای فیزیکی — physical gold -----------------------------------
    case LOT_VIEW = 'lot.view';
    case VAULT_DEPOSIT_REQUEST = 'vault.deposit.request';
    case VAULT_WITHDRAW = 'vault.withdraw';
    case LOT_SPLIT_MERGE = 'lot.split_merge';
    case ASSAY_RECORD = 'assay.record';
    case DELIVERY_CONFIRM = 'delivery.confirm';

    // --- د) مدیریت سازمان — organisation administration -------------------
    case USER_MANAGE = 'user.manage';
    case USER_ROLE_CHANGE = 'user.role.change';
    case KYC_EDIT = 'kyc.edit';
    case USER_LIMIT_SET = 'user.limit.set';
    case AUDIT_VIEW = 'audit.view';
    case ORGANIZATION_CLOSE = 'organization.close';

    // --- پلتفرم — platform staff ------------------------------------------
    case PLATFORM_ADMIN_ALL = 'platform.admin.all';
    case PLATFORM_KYC_REVIEW = 'platform.kyc.review';
    case PLATFORM_ORGANIZATION_SUSPEND = 'platform.organization.suspend';
    case PLATFORM_ORGANIZATION_UNSUSPEND = 'platform.organization.unsuspend';
    case PLATFORM_AML_MANAGE = 'platform.aml.manage';
    case PLATFORM_SETTLEMENT_MANAGE = 'platform.settlement.manage';
    case PLATFORM_LEDGER_ADJUST = 'platform.ledger.adjust';
    case PLATFORM_VAULT_MANAGE = 'platform.vault.manage';
    case PLATFORM_SUPPORT_VIEW = 'platform.support.view';
    case PLATFORM_AUDIT_VIEW = 'platform.audit.view';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** Coarse grouping used only for grouping in the admin UI and the seeder. */
    public function group(): string
    {
        return match ($this) {
            self::ORDER_BOOK_VIEW, self::ORDER_CREATE, self::ORDER_CANCEL_OWN,
            self::ORDER_CANCEL_ANY, self::RFQ_CREATE, self::RFQ_RESPOND,
            self::OTC_TRADE, self::TRADE_APPROVE_ABOVE_LIMIT => 'trading',

            self::BALANCE_VIEW, self::LEDGER_VIEW, self::PAYMENT_CONFIRM_SENT,
            self::PAYMENT_CONFIRM_RECEIVED, self::WITHDRAWAL_REQUEST,
            self::NETTING_ACCEPT, self::BANK_ACCOUNT_MANAGE,
            self::SETTLEMENT_CONFIRM => 'finance',

            self::LOT_VIEW, self::VAULT_DEPOSIT_REQUEST, self::VAULT_WITHDRAW,
            self::LOT_SPLIT_MERGE, self::ASSAY_RECORD,
            self::DELIVERY_CONFIRM => 'custody',

            self::USER_MANAGE, self::USER_ROLE_CHANGE, self::KYC_EDIT,
            self::USER_LIMIT_SET, self::AUDIT_VIEW,
            self::ORGANIZATION_CLOSE => 'organization',

            default => 'platform',
        };
    }

    /**
     * True when holding the permission is not by itself enough: the action also
     * needs a second, different user to approve it (personas doc §1.6).
     */
    public function requiresDualControl(): bool
    {
        return in_array($this, [
            self::VAULT_WITHDRAW,
            self::BANK_ACCOUNT_MANAGE,
            self::WITHDRAWAL_REQUEST,
            self::PLATFORM_LEDGER_ADJUST,
            self::PLATFORM_ORGANIZATION_UNSUSPEND,
        ], true);
    }

    /** Platform-staff permissions are never granted to a member organisation. */
    public function isPlatformScoped(): bool
    {
        return str_starts_with($this->value, 'platform.');
    }
}
