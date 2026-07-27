<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

/**
 * Outcome of the fat-finger check. docs/03-domain/07-pricing.md §7.8.
 */
enum PriceGuardStatus: string
{
    case ACCEPTED = 'ACCEPTED';
    case WARN = 'WARN';
    case REJECTED = 'REJECTED';

    public function requiresExplicitConfirmation(): bool
    {
        return $this === self::WARN;
    }
}
