<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

/** docs/03-domain/07-pricing.md §7.9. */
enum AlertStatus: string
{
    case ACTIVE = 'ACTIVE';
    case TRIGGERED = 'TRIGGERED';
    case DISABLED = 'DISABLED';
}
