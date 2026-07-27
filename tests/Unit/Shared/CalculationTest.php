<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Modules\Shared\Calculation\FeeTerms;
use App\Modules\Shared\Calculation\TradeValueCalculator;
use App\Modules\Shared\Support\IntMath;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\NumericInput;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Shared\ValueObjects\Weight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test vectors are taken verbatim from docs/11-appendix/01-formulas.md §1.12.
 */
#[Group('formulas')]
final class CalculationTest extends TestCase
{
    /** @return list<array{int, int, int}> */
    public static function fineWeightVectors(): array
    {
        return [
            [100_000, 10_000, 100_000],
            [100_000, 7_500, 75_000],
            [127_420, 7_500, 95_565],
            [250_000, 9_950, 248_750],
            [1, 9_999, 0],
            [3, 3_333, 0],
            [0, 9_950, 0],
        ];
    }

    #[Test]
    #[DataProvider('fineWeightVectors')]
    public function f1_fine_weight_matches_reference(int $grossMg, int $purity, int $expected): void
    {
        $fine = FineWeight::calculate(
            Weight::fromMilligrams($grossMg),
            Purity::fromScaled($purity),
        );

        self::assertSame($expected, $fine->milligrams);
    }

    /** @return list<array{int, int, int}> */
    public static function grossAmountVectors(): array
    {
        return [
            [248_750, 78_480_000, 19_521_900_000],
            [1_000, 78_480_000, 78_480_000],
            [1, 78_480_000, 78_480],
        ];
    }

    #[Test]
    #[DataProvider('grossAmountVectors')]
    public function f5_gross_amount_matches_reference(int $fineMg, int $price, int $expected): void
    {
        $gross = PricePerFineGram::fromRial($price)
            ->valueOf(FineWeight::fromMilligrams($fineMg));

        self::assertSame($expected, $gross->amount);
    }

    /** @return list<array{int, int, int}> */
    public static function feeVectors(): array
    {
        return [
            [19_521_900_000, 150, 29_282_850],
            [1, 150, 1],   // CEIL
            [0, 150, 0],
        ];
    }

    #[Test]
    #[DataProvider('feeVectors')]
    public function f8_fee_rounds_up(int $gross, int $rate, int $expected): void
    {
        $fee = FeeTerms::rate($rate)->applyTo(\App\Modules\Shared\ValueObjects\Rial::fromRial($gross));

        self::assertSame($expected, $fee->amount);
    }

    #[Test]
    public function worked_example_one_reproduces_the_documented_numbers(): void
    {
        $calc = new TradeValueCalculator();

        $valuation = $calc->valueOfGross(
            Weight::fromMilligrams(250_000),
            Purity::fromPpt(995),
            PricePerFineGram::fromRial(78_480_000),
            FeeTerms::rate(150),
            FeeTerms::rate(100),
        );

        self::assertSame(248_750, $valuation->fineWeight->milligrams);
        self::assertSame(19_521_900_000, $valuation->grossAmount->amount);
        self::assertSame(29_282_850, $valuation->buyerFee->amount);
        self::assertSame(19_521_900, $valuation->sellerFee->amount);
        self::assertSame(19_551_182_850, $valuation->buyerNet->amount);
        self::assertSame(19_502_378_100, $valuation->sellerNet->amount);
        self::assertSame(48_804_750, $valuation->platformIncome()->amount);
    }

    #[Test]
    public function f9_amounts_always_balance_across_random_inputs(): void
    {
        $calc = new TradeValueCalculator();

        for ($i = 0; $i < 2_000; $i++) {
            $valuation = $calc->value(
                FineWeight::fromMilligrams(random_int(1, 100_000_000)),
                PricePerFineGram::fromRial(random_int(1_000_000, 200_000_000)),
                FeeTerms::rate(random_int(0, 500)),
                FeeTerms::rate(random_int(0, 500)),
            );

            // The constructor asserts the invariant; reaching here means it held.
            self::assertSame(
                $valuation->buyerNet->minus($valuation->sellerNet)->amount,
                $valuation->platformIncome()->plus($valuation->totalTax())->amount,
            );
        }
    }

    #[Test]
    public function f2_required_gross_rounds_up(): void
    {
        $gross = FineWeight::fromMilligrams(100_000)
            ->requiredGrossAt(Purity::fromPpt(750));

        self::assertSame(133_334, $gross->milligrams);
    }

    #[Test]
    public function int_math_floor_handles_negative_numerators(): void
    {
        // Ledger amounts are signed, so floor must not degrade into truncation.
        self::assertSame(-2, IntMath::mulDivFloor(-3, 1, 2));
        self::assertSame(1, IntMath::mulDivFloor(3, 1, 2));
        self::assertSame(-1, IntMath::mulDivCeil(-3, 1, 2));
        self::assertSame(2, IntMath::mulDivCeil(3, 1, 2));
    }

    #[Test]
    public function numeric_input_parses_persian_digits_and_separators(): void
    {
        self::assertSame(1_247_320, NumericInput::toScaledInt('۱,۲۴۷.۳۲۰', 3));
        self::assertSame(250_000, NumericInput::toScaledInt('250', 3));
        self::assertSame(250_500, NumericInput::toScaledInt('250.5', 3));
        // Extra precision is truncated, never rounded up.
        self::assertSame(250_999, NumericInput::toScaledInt('250.9999', 3));
    }

    #[Test]
    public function numeric_input_round_trips(): void
    {
        self::assertSame('1247.320', NumericInput::fromScaledInt(1_247_320, 3));
        self::assertSame('1,247.320', NumericInput::group('1247.320'));
        self::assertSame('-0.001', NumericInput::fromScaledInt(-1, 3));
    }

    #[Test]
    public function purity_display_keeps_fractional_values(): void
    {
        self::assertSame('995', Purity::fromPpt(995)->toPpt());
        self::assertSame('995.5', Purity::fromString('995.5')->toPpt());
    }
}
