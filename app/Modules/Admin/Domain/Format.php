<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain;

/**
 * Display formatting for the panel.
 *
 * Every method takes an integer in the smallest unit and returns a string.
 * Nothing here converts to float, not even for display: 84_235_678_901 mg
 * divided by 1000 as a float loses digits at exactly the magnitudes a gold
 * platform reaches, and a dashboard that quietly rounds is a dashboard nobody
 * can reconcile against.
 */
final class Format
{
    /** Milligrams of fine gold as grams, with three decimals, integer-only. */
    public static function grams(int $milligrams): string
    {
        $sign = $milligrams < 0 ? '-' : '';
        $abs = abs($milligrams);

        $whole = intdiv($abs, 1000);
        $fraction = $abs % 1000;

        return $sign.self::thousands($whole).'.'.str_pad((string) $fraction, 3, '0', STR_PAD_LEFT);
    }

    /** Kilograms with three decimals, for the volume tile. */
    public static function kilograms(int $milligrams): string
    {
        $sign = $milligrams < 0 ? '-' : '';
        $abs = abs($milligrams);

        $whole = intdiv($abs, 1_000_000);
        $fraction = intdiv($abs % 1_000_000, 1000);

        return $sign.self::thousands($whole).'.'.str_pad((string) $fraction, 3, '0', STR_PAD_LEFT);
    }

    public static function rial(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';

        return $sign.self::thousands(abs($amount));
    }

    /** A signed integer with an explicit sign, for difference columns. */
    public static function signed(int $value): string
    {
        return ($value > 0 ? '+' : '').($value < 0 ? '-' : '').self::thousands(abs($value));
    }

    /**
     * Percentage change between two integers, in whole percent.
     *
     * Integer arithmetic throughout; a null previous value (or zero) has no
     * meaningful percentage and says so rather than reporting an infinity.
     */
    public static function changePercent(int $current, int $previous): ?int
    {
        if ($previous === 0) {
            return null;
        }

        return intdiv(($current - $previous) * 100, abs($previous));
    }

    public static function changeTone(int $current, int $previous): string
    {
        if ($current === $previous) {
            return 'flat';
        }

        return $current > $previous ? 'up' : 'down';
    }

    public static function changeLabel(int $current, int $previous): string
    {
        $percent = self::changePercent($current, $previous);

        if ($percent === null) {
            return $current === 0 ? '—' : 'جدید';
        }

        return ($percent >= 0 ? '▲ ' : '▼ ').abs($percent).'٪';
    }

    /** Thousands separators without touching float. */
    public static function thousands(int $value): string
    {
        $digits = (string) abs($value);
        $chunks = str_split(strrev($digits), 3);
        $grouped = strrev(implode(',', $chunks));

        return ($value < 0 ? '-' : '').$grouped;
    }

    /** Tone for a settlement / KYC / AML status, for the badge component. */
    public static function statusTone(string $status): string
    {
        return match ($status) {
            'ACTIVE', 'APPROVED', 'COMPLETED', 'SETTLED', 'VERIFIED', 'CLEARED',
            'FALSE_POSITIVE', 'EXECUTED', 'AVAILABLE' => 'ok',

            'OVERDUE', 'INFO_REQUIRED', 'UNDER_REVIEW', 'ENHANCED_REVIEW',
            'PENDING', 'PENDING_APPROVAL', 'SUBMITTED', 'IN_REVIEW',
            'RESTRICTED', 'ON_HOLD' => 'warn',

            'DEFAULTED', 'REJECTED', 'SUSPENDED', 'CLOSED', 'DISPUTED',
            'ESCALATED', 'CRITICAL', 'REVERSED' => 'bad',

            'OPEN', 'NEGOTIATION', 'UNDER_MEDIATION', 'ASSETS_LOCKED',
            'PAYMENT_PENDING', 'PAYMENT_DECLARED', 'PAYMENT_CONFIRMED' => 'info',

            default => 'muted',
        };
    }

    /** Severity tone for AML flags, where CRITICAL must never look neutral. */
    public static function severityTone(string $severity): string
    {
        return match ($severity) {
            'CRITICAL' => 'bad',
            'HIGH' => 'warn',
            'MEDIUM' => 'info',
            default => 'muted',
        };
    }
}
