<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

enum RepresentativeStatus: string
{
    case PENDING = 'PENDING';
    case ACTIVE = 'ACTIVE';
    case EXPIRED = 'EXPIRED';
    case REVOKED = 'REVOKED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
