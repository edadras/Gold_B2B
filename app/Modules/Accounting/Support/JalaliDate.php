<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Gregorian ⇄ Jalali conversion, integer arithmetic only.
 *
 * Vouchers are numbered by the Jalali year (§9.7, «GB-1404-08-00142») and every
 * report the member reads is dated شمسی, so the platform needs this in more
 * than one module. It is deliberately duplicated in Reporting\Support rather
 * than promoted into Shared: this agent's brief forbids touching Shared/, and a
 * pure function with a fixed algorithm is a cheaper duplication than a
 * cross-module dependency. Consolidating the two copies under
 * Shared\Support\JalaliDate is a one-commit follow-up.
 */
final class JalaliDate
{
    /** Cumulative Gregorian day counts at the start of each month, non-leap. */
    private const GREGORIAN_MONTH_DAYS = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

    private function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly int $day,
    ) {}

    /** @param string $date Y-m-d */
    public static function fromGregorianString(string $date): self
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false) {
            throw new InvalidArgumentException("Not a Y-m-d date: {$date}");
        }

        return self::fromGregorian(
            (int) $parsed->format('Y'),
            (int) $parsed->format('n'),
            (int) $parsed->format('j'),
        );
    }

    public static function fromGregorian(int $gy, int $gm, int $gd): self
    {
        if ($gm < 1 || $gm > 12) {
            throw new InvalidArgumentException("Month out of range: {$gm}");
        }

        $shifted = $gm > 2 ? $gy + 1 : $gy;

        $days = 355666
            + (365 * $gy)
            + intdiv($shifted + 3, 4)
            - intdiv($shifted + 99, 100)
            + intdiv($shifted + 399, 400)
            + $gd
            + self::GREGORIAN_MONTH_DAYS[$gm - 1];

        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;

        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return new self($jy, $jm, $jd);
    }

    /** "۱۴۰۴/۰۸/۰۵" in ASCII digits: 1404-08-05. */
    public function format(string $separator = '-'): string
    {
        return sprintf('%04d%s%02d%s%02d', $this->year, $separator, $this->month, $separator, $this->day);
    }

    public function yearMonth(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
