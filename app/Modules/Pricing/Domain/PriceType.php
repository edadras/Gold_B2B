<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

/**
 * What a tick measures. docs/03-domain/07-pricing.md §7.3.
 */
enum PriceType: string
{
    case OUNCE_USD = 'OUNCE_USD';
    case USD_IRR = 'USD_IRR';
    case MESGHAL_IRR = 'MESGHAL_IRR';
    case FINE_GRAM_IRR = 'FINE_GRAM_IRR';

    /**
     * Number of decimal digits the raw `value` column carries for this type.
     * OUNCE_USD is stored in micro-dollars, everything else in whole rial.
     */
    public function defaultScale(): int
    {
        return match ($this) {
            self::OUNCE_USD => 6,
            default => 0,
        };
    }

    /**
     * Upper bound of filter 4 ("sane range") when the source row does not
     * override it. Deliberately generous — this catches decimal-point errors,
     * not market moves.
     */
    public function defaultMaxSaneValue(): int
    {
        return match ($this) {
            self::OUNCE_USD => 100_000_000_000,      // $100,000/oz in micro-dollars
            self::USD_IRR => 100_000_000,            // 100,000,000 rial per USD
            self::MESGHAL_IRR => 100_000_000_000,
            self::FINE_GRAM_IRR => 100_000_000_000,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OUNCE_USD => 'اونس جهانی (دلار)',
            self::USD_IRR => 'نرخ دلار (ریال)',
            self::MESGHAL_IRR => 'مظنه مثقال (ریال)',
            self::FINE_GRAM_IRR => 'گرم طلای خالص (ریال)',
        };
    }
}
