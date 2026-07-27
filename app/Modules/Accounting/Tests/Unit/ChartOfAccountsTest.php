<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Tests\Unit;

use App\Modules\Accounting\Domain\AccountCode;
use App\Modules\Accounting\Domain\AccountType;
use App\Modules\Accounting\Domain\BalanceSide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The chart of §9.1, code for code.
 *
 * A pure unit test on purpose — this is the one assertion in the module that
 * must not need a database, because it is checking that the code agrees with
 * the specification rather than with itself.
 */
final class ChartOfAccountsTest extends TestCase
{
    /** Every code printed in docs/03-domain/09-accounting.md §9.1. */
    private const DOCUMENTED = [
        '1101' => 'صندوق',
        '1102' => 'بانک',
        '1103' => 'موجودی ریالی در سامانه',
        '1110' => 'موجودی طلا — در خزانه',
        '1111' => 'موجودی طلا — نزد خود',
        '1112' => 'موجودی طلا — در ری‌گیری',
        '1113' => 'موجودی طلا — در راه',
        '1120' => 'حساب‌های دریافتنی — ریالی',
        '1121' => 'حساب‌های دریافتنی — طلایی',
        '1130' => 'پیش‌پرداخت‌ها',
        '1190' => 'دارایی‌های قفل‌شده (رزرو/تسویه)',
        '2101' => 'حساب‌های پرداختنی — ریالی',
        '2102' => 'حساب‌های پرداختنی — طلایی',
        '2110' => 'پیش‌دریافت‌ها',
        '2120' => 'کارمزد پرداختنی',
        '2130' => 'مالیات پرداختنی',
        '2140' => 'طلای امانی نزد ما (متعلق به دیگران)',
        '3101' => 'سرمایه',
        '3201' => 'سود انباشته',
        '3901' => 'سود/زیان دوره جاری',
        '4101' => 'فروش طلا',
        '4201' => 'سود تحقق‌یافته معاملات',
        '4901' => 'سایر درآمدها',
        '5101' => 'بهای تمام‌شده طلای فروخته‌شده',
        '5201' => 'کارمزد سامانه',
        '5202' => 'کارمزد بانکی',
        '5301' => 'هزینه ری‌گیری',
        '5302' => 'هزینه حمل و بیمه',
        '5303' => 'هزینه نگهداری خزانه',
        '5401' => 'افت ذوب',
        '5501' => 'جریمه تأخیر',
        '5901' => 'سایر هزینه‌ها',
        '9101' => 'تعهدات معاملاتی باز',
        '9201' => 'طلای امانی',
    ];

    #[Test]
    public function the_chart_matches_the_document_exactly(): void
    {
        $implemented = [];

        foreach (AccountCode::cases() as $code) {
            $implemented[$code->value] = $code->label();
        }

        self::assertSame(self::DOCUMENTED, $implemented);
    }

    #[Test]
    public function account_types_follow_the_leading_digit(): void
    {
        self::assertSame(AccountType::ASSET, AccountCode::GOLD_IN_VAULT->type());
        self::assertSame(AccountType::LIABILITY, AccountCode::PAYABLE_RIAL->type());
        self::assertSame(AccountType::EQUITY, AccountCode::CAPITAL->type());
        self::assertSame(AccountType::REVENUE, AccountCode::GOLD_SALES->type());
        self::assertSame(AccountType::EXPENSE, AccountCode::COST_OF_GOODS_SOLD->type());
        self::assertSame(AccountType::MEMO, AccountCode::OPEN_TRADE_COMMITMENTS->type());
    }

    #[Test]
    public function normal_balances_are_the_classical_ones(): void
    {
        self::assertSame(BalanceSide::DEBIT, AccountType::ASSET->normalBalance());
        self::assertSame(BalanceSide::DEBIT, AccountType::EXPENSE->normalBalance());
        self::assertSame(BalanceSide::CREDIT, AccountType::LIABILITY->normalBalance());
        self::assertSame(BalanceSide::CREDIT, AccountType::EQUITY->normalBalance());
        self::assertSame(BalanceSide::CREDIT, AccountType::REVENUE->normalBalance());
    }

    #[Test]
    public function only_dual_unit_accounts_carry_a_gold_quantity(): void
    {
        $goldBearing = array_values(array_map(
            static fn (AccountCode $c): string => $c->value,
            array_filter(AccountCode::cases(), static fn (AccountCode $c): bool => $c->carriesGoldQuantity()),
        ));

        self::assertSame(
            ['1110', '1111', '1112', '1113', '1121', '2102', '2140', '9101', '9201'],
            $goldBearing,
        );

        // §9.2's example: ۱۱۰۳ is rial only, which is precisely why the gold
        // column cannot be balanced against it and the two-set split exists.
        self::assertFalse(AccountCode::PLATFORM_RIAL_BALANCE->carriesGoldQuantity());
    }

    #[Test]
    public function memo_accounts_stay_out_of_the_financial_statements(): void
    {
        self::assertFalse(AccountType::MEMO->isStatementAccount());
        self::assertTrue(AccountType::ASSET->isStatementAccount());
    }
}
