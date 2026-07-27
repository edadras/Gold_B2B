<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain;

enum BankAccountStatus: string
{
    case PENDING = 'PENDING';
    case VERIFIED = 'VERIFIED';
    case REJECTED = 'REJECTED';
    case DISABLED = 'DISABLED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
