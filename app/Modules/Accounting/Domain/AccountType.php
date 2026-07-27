<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * The five classical account classes plus MEMO.
 *
 * MEMO (حساب‌های انتظامی, docs/03-domain/09-accounting.md §9.1 class ۹) exists
 * because the gold column needs a contra account: a rial-only account such as
 * ۱۱۰۳ can never balance a weight, so the gold set of lines is posted against
 * ۹۱۰۱/۹۲۰۱ instead. MEMO accounts are excluded from the balance sheet and the
 * profit and loss statement.
 */
enum AccountType: string
{
    case ASSET = 'ASSET';
    case LIABILITY = 'LIABILITY';
    case EQUITY = 'EQUITY';
    case REVENUE = 'REVENUE';
    case EXPENSE = 'EXPENSE';
    case MEMO = 'MEMO';

    /**
     * Side on which a positive balance sits.
     *
     * Used by the trial balance to decide whether a net movement is reported as
     * «بد» (debit) or «بس» (credit).
     */
    public function normalBalance(): BalanceSide
    {
        return match ($this) {
            self::ASSET, self::EXPENSE, self::MEMO => BalanceSide::DEBIT,
            self::LIABILITY, self::EQUITY, self::REVENUE => BalanceSide::CREDIT,
        };
    }

    /** MEMO accounts never appear in financial statements. */
    public function isStatementAccount(): bool
    {
        return $this !== self::MEMO;
    }

    /** Revenue and expense accounts are the ones closed out at period end. */
    public function isTemporary(): bool
    {
        return $this === self::REVENUE || $this === self::EXPENSE;
    }

    public function label(): string
    {
        return match ($this) {
            self::ASSET => 'دارایی',
            self::LIABILITY => 'بدهی',
            self::EQUITY => 'حقوق مالکانه',
            self::REVENUE => 'درآمد',
            self::EXPENSE => 'هزینه',
            self::MEMO => 'انتظامی',
        };
    }
}
