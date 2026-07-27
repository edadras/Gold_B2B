<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

/**
 * Nature of the feed behind a price source. docs/03-domain/07-pricing.md §7.3.
 */
enum SourceType: string
{
    case OUNCE = 'OUNCE';
    case FX = 'FX';
    case MESGHAL = 'MESGHAL';
    case MANUAL = 'MANUAL';

    public function isAutomatic(): bool
    {
        return $this !== self::MANUAL;
    }
}
