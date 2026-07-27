<?php

declare(strict_types=1);

namespace App\Modules\Admin\Tests\Unit;

use App\Modules\Admin\Domain\Format;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Display formatting must stay integer-exact.
 *
 * AGENT_BRIEF rule 1 forbids float in a financial path, and a dashboard is a
 * financial path the moment someone reconciles against it. The large-magnitude
 * cases below are the ones a float would silently round.
 */
final class FormatTest extends TestCase
{
    #[Test]
    public function milligrams_render_as_grams_without_losing_a_digit(): void
    {
        self::assertSame('1.000', Format::grams(1000));
        self::assertSame('0.001', Format::grams(1));
        self::assertSame('-2.500', Format::grams(-2500));

        // 9007199254740993 mg is beyond float's exact integer range; a float
        // conversion would render the wrong number here.
        self::assertSame('9,007,199,254,740.993', Format::grams(9_007_199_254_740_993));
    }

    #[Test]
    public function kilograms_round_trip_the_documented_dashboard_figure(): void
    {
        // §1.9's «۸۴.۲ کیلو».
        self::assertSame('84.200', Format::kilograms(84_200_000));
    }

    #[Test]
    public function rial_amounts_are_grouped_not_rounded(): void
    {
        self::assertSame('1,200,000,000', Format::rial(1_200_000_000));
        self::assertSame('-500', Format::rial(-500));
        self::assertSame('0', Format::rial(0));
    }

    #[Test]
    public function a_difference_column_always_shows_its_sign(): void
    {
        self::assertSame('+1,000', Format::signed(1000));
        self::assertSame('-1,000', Format::signed(-1000));
        self::assertSame('0', Format::signed(0));
    }

    #[Test]
    public function a_change_against_zero_has_no_percentage_and_says_so(): void
    {
        self::assertNull(Format::changePercent(50, 0));
        self::assertSame('جدید', Format::changeLabel(50, 0));
        self::assertSame('—', Format::changeLabel(0, 0));
        self::assertSame(20, Format::changePercent(120, 100));
        self::assertSame('▼ 20٪', Format::changeLabel(80, 100));
    }

    #[Test]
    public function a_critical_severity_never_reads_as_neutral(): void
    {
        self::assertSame('bad', Format::severityTone('CRITICAL'));
        self::assertSame('warn', Format::severityTone('HIGH'));
        self::assertSame('muted', Format::severityTone('LOW'));

        // An unknown status is muted, not accidentally green.
        self::assertSame('muted', Format::statusTone('SOMETHING_NEW'));
        self::assertSame('bad', Format::statusTone('DEFAULTED'));
        self::assertSame('ok', Format::statusTone('COMPLETED'));
    }
}
