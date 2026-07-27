<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain;

enum LicenseStatus: string
{
    case PENDING = 'PENDING';
    case VALID = 'VALID';
    case EXPIRING_SOON = 'EXPIRING_SOON';
    case EXPIRED = 'EXPIRED';
    case REVOKED = 'REVOKED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function isUsable(): bool
    {
        return in_array($this, [self::VALID, self::EXPIRING_SOON], true);
    }
}
