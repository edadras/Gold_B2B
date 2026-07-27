<?php

declare(strict_types=1);

namespace App\Modules\Shared\ValueObjects;

use InvalidArgumentException;

/**
 * Decimal string <-> scaled integer conversion that never touches float.
 *
 * User input arrives as text (possibly with Persian or Arabic digits and
 * thousands separators); financial values are stored as scaled integers. This
 * is the only sanctioned bridge between the two.
 */
final class NumericInput
{
    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const ARABIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private function __construct() {}

    /**
     * Normalise Persian/Arabic digits and separators to plain ASCII.
     */
    public static function normalizeDigits(string $input): string
    {
        $output = $input;

        for ($i = 0; $i < 10; $i++) {
            $output = str_replace([self::PERSIAN_DIGITS[$i], self::ARABIC_DIGITS[$i]], (string) $i, $output);
        }

        // Arabic decimal separator and thousands separator
        $output = str_replace(['٫', '،', '٬'], ['.', ',', ','], $output);

        return $output;
    }

    /**
     * "1,247.32" with scale 3 -> 1247320
     *
     * Extra decimal places beyond $scale are truncated (never rounded up) so a
     * user cannot conjure weight they do not have by typing more digits.
     */
    public static function toScaledInt(string $input, int $scale): int
    {
        $normalized = trim(self::normalizeDigits($input));
        $normalized = str_replace([',', ' ', '_'], '', $normalized);

        if ($normalized === '') {
            throw new InvalidArgumentException('Numeric input is empty');
        }

        $negative = false;
        if (str_starts_with($normalized, '-')) {
            $negative = true;
            $normalized = substr($normalized, 1);
        } elseif (str_starts_with($normalized, '+')) {
            $normalized = substr($normalized, 1);
        }

        if (! preg_match('/^\d*(?:\.\d*)?$/', $normalized) || $normalized === '' || $normalized === '.') {
            throw new InvalidArgumentException("Invalid numeric input: {$input}");
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');

        $whole = $whole === '' ? '0' : $whole;
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);

        $combined = $whole.$fraction;
        $combined = ltrim($combined, '0');
        $value = $combined === '' ? 0 : (int) $combined;

        if ((string) $value !== ($combined === '' ? '0' : $combined)) {
            throw new InvalidArgumentException("Numeric input out of range: {$input}");
        }

        return $negative ? -$value : $value;
    }

    /**
     * 1247320 with scale 3 -> "1247.320"
     */
    public static function fromScaledInt(int $value, int $scale): string
    {
        $negative = $value < 0;
        $abs = (string) abs($value);

        if ($scale === 0) {
            return ($negative ? '-' : '').$abs;
        }

        $abs = str_pad($abs, $scale + 1, '0', STR_PAD_LEFT);
        $whole = substr($abs, 0, -$scale);
        $fraction = substr($abs, -$scale);

        return ($negative ? '-' : '').$whole.'.'.$fraction;
    }

    /** Insert thousands separators into the integer part of a decimal string. */
    public static function group(string $decimal): string
    {
        $negative = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '-');

        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, null);

        $whole = strrev(implode(',', str_split(strrev($whole), 3)));

        return ($negative ? '-' : '').$whole.($fraction !== null ? '.'.$fraction : '');
    }

    /** Convert ASCII digits to Persian digits for display. */
    public static function toPersianDigits(string $input): string
    {
        $output = $input;
        for ($i = 0; $i < 10; $i++) {
            $output = str_replace((string) $i, self::PERSIAN_DIGITS[$i], $output);
        }

        return $output;
    }
}
