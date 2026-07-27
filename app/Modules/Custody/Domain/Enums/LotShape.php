<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Enums;

/** Physical form of the piece. Schema §2.3. */
enum LotShape: string
{
    case BAR = 'BAR';
    case GRAIN = 'GRAIN';
    case SCRAP = 'SCRAP';
    case COIN = 'COIN';
    case OTHER = 'OTHER';

    /** Grain and scrap cannot be split without melting. */
    public function isPhysicallySplittable(): bool
    {
        return $this === self::BAR || $this === self::GRAIN || $this === self::SCRAP;
    }
}
