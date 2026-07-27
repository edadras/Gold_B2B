<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * What an authorised representative may do on behalf of the member
 * (docs/03-domain/01-identity-kyc.md §1.7).
 *
 * A user holding Role::TRADER without a valid TRADE representative record may
 * not trade; expiry of the record automatically suspends that access.
 */
enum AuthorityType: string
{
    case TRADE = 'TRADE';
    case DELIVERY = 'DELIVERY';
    case SIGNATURE = 'SIGNATURE';
    case FULL = 'FULL';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function covers(self $needed): bool
    {
        return $this === self::FULL || $this === $needed;
    }
}
