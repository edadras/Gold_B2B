<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Modules\Shared\Support\JalaliDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the single Jalali implementation.
 *
 * This class previously existed three times with diverging behaviour; these
 * vectors exist so a future divergence fails the build instead of quietly
 * dating a voucher differently from the report that lists it.
 */
final class JalaliDateTest extends TestCase
{
    /** @return list<array{string, int, int, int}> */
    public static function conversions(): array
    {
        return [
            // Nowruz — first day of the Iranian year
            ['2025-03-21', 1404, 1, 1],
            ['2026-03-21', 1405, 1, 1],
            // Last day of a 31-day first half
            ['2025-09-22', 1404, 6, 31],
            // Start of the 30-day second half
            ['2025-09-23', 1404, 7, 1],
            // End of year, non-leap
            ['2026-03-20', 1404, 12, 29],
            // A date used throughout the documentation examples
            ['2025-10-27', 1404, 8, 5],
        ];
    }

    #[Test]
    #[DataProvider('conversions')]
    public function converts_gregorian_to_jalali(string $gregorian, int $y, int $m, int $d): void
    {
        $jalali = JalaliDate::fromGregorianString($gregorian);

        self::assertSame($y, $jalali->year, "year for {$gregorian}");
        self::assertSame($m, $jalali->month, "month for {$gregorian}");
        self::assertSame($d, $jalali->day, "day for {$gregorian}");
    }

    #[Test]
    public function formats_with_the_requested_separator(): void
    {
        $jalali = JalaliDate::fromGregorianString('2025-10-27');

        // Display convention.
        self::assertSame('1404/08/05', $jalali->format());
        // Sortable key convention, used for period and voucher codes.
        self::assertSame('1404-08-05', $jalali->format('-'));
    }

    #[Test]
    public function pads_month_and_day(): void
    {
        $jalali = JalaliDate::fromGregorianString('2025-03-21');

        self::assertSame('1404/01/01', $jalali->format());
    }
}
