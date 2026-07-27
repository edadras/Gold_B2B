<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

/**
 * Which rung of the fallback ladder produced the price in use.
 * docs/03-domain/07-pricing.md §7.4.
 */
enum PriceSourceMode: string
{
    case PRIMARY = 'PRIMARY';
    case FALLBACK = 'FALLBACK';
    case MANUAL = 'MANUAL';
    case NONE = 'NONE';

    public function requiresUserBanner(): bool
    {
        return $this === self::MANUAL || $this === self::NONE;
    }
}
