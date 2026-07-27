<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

/**
 * Physical metal behind a GOLD-class ledger account.
 *
 * NULL on RIAL accounts. Only GOLD is traded today; the other cases exist so
 * adding a metal later is a data change rather than a schema change.
 */
enum MetalType: string
{
    case GOLD = 'GOLD';
    case SILVER = 'SILVER';
    case PLATINUM = 'PLATINUM';
}
