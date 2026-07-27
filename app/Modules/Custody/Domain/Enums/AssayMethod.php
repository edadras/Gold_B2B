<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Enums;

/** Assay technique — docs/03-domain/02-gold-lot-assay.md §2.4. */
enum AssayMethod: string
{
    case FIRE_ASSAY = 'FIRE_ASSAY';
    case XRF = 'XRF';
    case ICP = 'ICP';
    case OTHER = 'OTHER';

    /** Fire assay is destructive but definitive; XRF only reads the surface. */
    public function isDestructive(): bool
    {
        return $this === self::FIRE_ASSAY;
    }
}
