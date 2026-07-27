<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

/** docs/03-domain/07-pricing.md §7.9. */
enum AlertCondition: string
{
    case ABOVE = 'ABOVE';
    case BELOW = 'BELOW';
    case CHANGE_PERCENT = 'CHANGE_PERCENT';
}
