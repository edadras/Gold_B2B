<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain;

enum DisputePriority: string
{
    case LOW = 'LOW';
    case NORMAL = 'NORMAL';
    case HIGH = 'HIGH';
    case URGENT = 'URGENT';

    public function label(): string
    {
        return match ($this) {
            self::LOW => 'کم',
            self::NORMAL => 'عادی',
            self::HIGH => 'بالا',
            self::URGENT => 'فوری',
        };
    }

    /** Sort weight for an operator's queue; higher is more pressing. */
    public function weight(): int
    {
        return match ($this) {
            self::LOW => 1,
            self::NORMAL => 2,
            self::HIGH => 3,
            self::URGENT => 4,
        };
    }
}
