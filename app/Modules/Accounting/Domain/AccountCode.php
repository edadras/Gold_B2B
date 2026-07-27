<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * The default chart of accounts, verbatim from docs/03-domain/09-accounting.md §9.1.
 *
 * The enum is the source of truth; `chart_of_accounts` is seeded from it per
 * organisation so a member can rename a caption without the posting rules
 * losing the account they mean. Codes are strings, not ints, because the chart
 * is hierarchical (11 → 1110) and leading structure matters.
 */
enum AccountCode: string
{
    // ── ۱ دارایی‌ها ───────────────────────────────────────────────────────
    case CASH = '1101';
    case BANK = '1102';
    case PLATFORM_RIAL_BALANCE = '1103';
    case GOLD_IN_VAULT = '1110';
    case GOLD_ON_HAND = '1111';
    case GOLD_IN_REFINING = '1112';
    case GOLD_IN_TRANSIT = '1113';
    case RECEIVABLE_RIAL = '1120';
    case RECEIVABLE_GOLD = '1121';
    case PREPAYMENTS = '1130';
    case LOCKED_ASSETS = '1190';

    // ── ۲ بدهی‌ها ─────────────────────────────────────────────────────────
    case PAYABLE_RIAL = '2101';
    case PAYABLE_GOLD = '2102';
    case ADVANCES_RECEIVED = '2110';
    case FEE_PAYABLE = '2120';
    case TAX_PAYABLE = '2130';
    case CUSTODIAL_GOLD_LIABILITY = '2140';

    // ── ۳ حقوق مالکانه ────────────────────────────────────────────────────
    case CAPITAL = '3101';
    case RETAINED_EARNINGS = '3201';
    case CURRENT_PERIOD_RESULT = '3901';

    // ── ۴ درآمدها ─────────────────────────────────────────────────────────
    case GOLD_SALES = '4101';
    case REALIZED_TRADING_PROFIT = '4201';
    case OTHER_INCOME = '4901';

    // ── ۵ هزینه‌ها ────────────────────────────────────────────────────────
    case COST_OF_GOODS_SOLD = '5101';
    case PLATFORM_FEE = '5201';
    case BANK_FEE = '5202';
    case REFINING_COST = '5301';
    case SHIPPING_AND_INSURANCE = '5302';
    case VAULT_STORAGE_COST = '5303';
    case MELT_LOSS = '5401';
    case LATE_PENALTY = '5501';
    case OTHER_EXPENSE = '5901';

    // ── ۹ حساب‌های انتظامی ────────────────────────────────────────────────
    case OPEN_TRADE_COMMITMENTS = '9101';
    case CUSTODIAL_GOLD_MEMO = '9201';

    public function type(): AccountType
    {
        return match (substr($this->value, 0, 1)) {
            '1' => AccountType::ASSET,
            '2' => AccountType::LIABILITY,
            '3' => AccountType::EQUITY,
            '4' => AccountType::REVENUE,
            '5' => AccountType::EXPENSE,
            default => AccountType::MEMO,
        };
    }

    /** Persian caption, exactly as printed in §9.1. */
    public function label(): string
    {
        return match ($this) {
            self::CASH => 'صندوق',
            self::BANK => 'بانک',
            self::PLATFORM_RIAL_BALANCE => 'موجودی ریالی در سامانه',
            self::GOLD_IN_VAULT => 'موجودی طلا — در خزانه',
            self::GOLD_ON_HAND => 'موجودی طلا — نزد خود',
            self::GOLD_IN_REFINING => 'موجودی طلا — در ری‌گیری',
            self::GOLD_IN_TRANSIT => 'موجودی طلا — در راه',
            self::RECEIVABLE_RIAL => 'حساب‌های دریافتنی — ریالی',
            self::RECEIVABLE_GOLD => 'حساب‌های دریافتنی — طلایی',
            self::PREPAYMENTS => 'پیش‌پرداخت‌ها',
            self::LOCKED_ASSETS => 'دارایی‌های قفل‌شده (رزرو/تسویه)',
            self::PAYABLE_RIAL => 'حساب‌های پرداختنی — ریالی',
            self::PAYABLE_GOLD => 'حساب‌های پرداختنی — طلایی',
            self::ADVANCES_RECEIVED => 'پیش‌دریافت‌ها',
            self::FEE_PAYABLE => 'کارمزد پرداختنی',
            self::TAX_PAYABLE => 'مالیات پرداختنی',
            self::CUSTODIAL_GOLD_LIABILITY => 'طلای امانی نزد ما (متعلق به دیگران)',
            self::CAPITAL => 'سرمایه',
            self::RETAINED_EARNINGS => 'سود انباشته',
            self::CURRENT_PERIOD_RESULT => 'سود/زیان دوره جاری',
            self::GOLD_SALES => 'فروش طلا',
            self::REALIZED_TRADING_PROFIT => 'سود تحقق‌یافته معاملات',
            self::OTHER_INCOME => 'سایر درآمدها',
            self::COST_OF_GOODS_SOLD => 'بهای تمام‌شده طلای فروخته‌شده',
            self::PLATFORM_FEE => 'کارمزد سامانه',
            self::BANK_FEE => 'کارمزد بانکی',
            self::REFINING_COST => 'هزینه ری‌گیری',
            self::SHIPPING_AND_INSURANCE => 'هزینه حمل و بیمه',
            self::VAULT_STORAGE_COST => 'هزینه نگهداری خزانه',
            self::MELT_LOSS => 'افت ذوب',
            self::LATE_PENALTY => 'جریمه تأخیر',
            self::OTHER_EXPENSE => 'سایر هزینه‌ها',
            self::OPEN_TRADE_COMMITMENTS => 'تعهدات معاملاتی باز',
            self::CUSTODIAL_GOLD_MEMO => 'طلای امانی',
        };
    }

    /**
     * Whether this account carries a fine-gold quantity alongside its rial value
     * (docs/03-domain/09-accounting.md §9.2 — «حساب‌های دوواحدی»).
     *
     * Only these may appear on a GOLD line set.
     */
    public function carriesGoldQuantity(): bool
    {
        return match ($this) {
            self::GOLD_IN_VAULT,
            self::GOLD_ON_HAND,
            self::GOLD_IN_REFINING,
            self::GOLD_IN_TRANSIT,
            self::RECEIVABLE_GOLD,
            self::PAYABLE_GOLD,
            self::CUSTODIAL_GOLD_LIABILITY,
            self::OPEN_TRADE_COMMITMENTS,
            self::CUSTODIAL_GOLD_MEMO => true,
            default => false,
        };
    }

    /** The parent group caption, e.g. "11 — دارایی‌های جاری". */
    public function groupCode(): string
    {
        return substr($this->value, 0, 2);
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::cases();
    }

    /** @return array<int, self> */
    public static function ofType(AccountType $type): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $code): bool => $code->type() === $type,
        ));
    }

    public static function tryFromCode(string $code): ?self
    {
        return self::tryFrom($code);
    }
}
