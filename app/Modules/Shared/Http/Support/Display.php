<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Support;

use App\Modules\Shared\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * Builds the `_display` and `_jalali` companion fields of
 * docs/05-api/01-conventions.md §1.12.
 *
 * Rule from the doc, enforced by construction: these are strings for humans.
 * The integer field next to them is always the source of truth, and nothing in
 * the platform ever parses one back. Everything here is integer arithmetic and
 * string building — no float ever touches a rial or a milligram.
 *
 * `?include_display=false` drops them all; the mobile client uses that on list
 * endpoints where it formats locally anyway.
 */
final class Display
{
    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    public static function enabledFor(Request $request): bool
    {
        $flag = $request->query('include_display');

        return ! in_array($flag, ['false', '0', 'no'], true);
    }

    /** "۷۸,۵۱۰,۰۰۰ ریال" */
    public static function rial(?int $amount): ?string
    {
        return $amount === null ? null : self::digits(self::grouped($amount)).' ریال';
    }

    /** Grams to three decimals: 1_047_320 mg → "۱,۰۴۷.۳۲۰ گرم". */
    public static function grams(?int $milligrams): ?string
    {
        if ($milligrams === null) {
            return null;
        }

        $sign = $milligrams < 0 ? '-' : '';
        $abs = abs($milligrams);

        $whole = self::grouped(intdiv($abs, 1000));
        $fraction = str_pad((string) ($abs % 1000), 3, '0', STR_PAD_LEFT);

        return $sign.self::digits($whole.'.'.$fraction).' گرم';
    }

    /** Purity in ten-thousandths rendered per-mille: 9950 → "۹۹۵". */
    public static function purity(?int $x10000): ?string
    {
        return $x10000 === null ? null : self::digits((string) intdiv($x10000, 10));
    }

    /** Basis points as a percentage: 150 → "۱.۵۰٪". */
    public static function bps(?int $bps): ?string
    {
        if ($bps === null) {
            return null;
        }

        $sign = $bps < 0 ? '-' : '';
        $abs = abs($bps);

        return $sign.self::digits(intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT)).'٪';
    }

    /** UTC ISO-8601 with milliseconds, the only machine-readable date format. */
    public static function iso(DateTimeInterface|string|null $at): ?string
    {
        if ($at === null) {
            return null;
        }

        if (is_string($at)) {
            return $at;
        }

        return $at instanceof CarbonInterface
            ? $at->clone()->utc()->toIso8601ZuluString('millisecond')
            : self::iso(CarbonImmutable::instance($at));
    }

    /** "۱۴۰۵/۰۵/۰۵ ۱۲:۴۵:۳۳" in Tehran local time, which is what the user reads. */
    public static function jalali(DateTimeInterface|string|null $at, bool $withTime = true): ?string
    {
        if ($at === null) {
            return null;
        }

        $moment = $at instanceof CarbonInterface
            ? $at->clone()
            : CarbonImmutable::parse(is_string($at) ? $at : $at->format(DateTimeInterface::ATOM));

        $local = $moment->setTimezone(config('goldb2b.market.timezone', 'Asia/Tehran'));

        $jalali = JalaliDate::fromGregorian(
            (int) $local->format('Y'),
            (int) $local->format('n'),
            (int) $local->format('j'),
        );

        $text = $jalali->format();

        if ($withTime) {
            $text .= ' '.$local->format('H:i:s');
        }

        return self::digits($text);
    }

    /** ASCII digits to Persian ones. Leaves separators and letters alone. */
    public static function digits(string $ascii): string
    {
        return strtr($ascii, [
            '0' => self::PERSIAN_DIGITS[0], '1' => self::PERSIAN_DIGITS[1],
            '2' => self::PERSIAN_DIGITS[2], '3' => self::PERSIAN_DIGITS[3],
            '4' => self::PERSIAN_DIGITS[4], '5' => self::PERSIAN_DIGITS[5],
            '6' => self::PERSIAN_DIGITS[6], '7' => self::PERSIAN_DIGITS[7],
            '8' => self::PERSIAN_DIGITS[8], '9' => self::PERSIAN_DIGITS[9],
        ]);
    }

    /** Thousands separators without number_format(), which speaks float. */
    private static function grouped(int $value): string
    {
        $sign = $value < 0 ? '-' : '';
        $digits = (string) abs($value);

        $out = '';
        $length = strlen($digits);

        for ($i = 0; $i < $length; $i++) {
            if ($i > 0 && ($length - $i) % 3 === 0) {
                $out .= ',';
            }
            $out .= $digits[$i];
        }

        return $sign.$out;
    }
}
