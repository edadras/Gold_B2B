<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain;

/** Lifecycle of a pledge. docs/03-domain/11-risk-credit.md §11.6. */
enum CollateralStatus: string
{
    case PENDING = 'PENDING';
    case ACTIVE = 'ACTIVE';
    case RELEASED = 'RELEASED';
    case LIQUIDATED = 'LIQUIDATED';

    public function countsTowardsCoverage(): bool
    {
        return $this === self::ACTIVE;
    }
}
