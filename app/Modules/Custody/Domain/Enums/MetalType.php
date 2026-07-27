<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Enums;

/** Schema §2.3. Only GOLD is in scope for phase 1. */
enum MetalType: string
{
    case GOLD = 'GOLD';
    case SILVER = 'SILVER';
    case PLATINUM = 'PLATINUM';
}
