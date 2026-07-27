<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Legal nature of a member organisation.
 *
 * Drives which KYC documents and identity fields are mandatory
 * (docs/03-domain/01-identity-kyc.md §1.3).
 */
enum OrganizationType: string
{
    case INDIVIDUAL = 'INDIVIDUAL';
    case LEGAL_ENTITY = 'LEGAL_ENTITY';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** An individual member is identified by a 10-digit national id. */
    public function requiresNationalId(): bool
    {
        return $this === self::INDIVIDUAL;
    }

    /** A legal entity is identified by an 11-digit legal id + registration number. */
    public function requiresLegalId(): bool
    {
        return $this === self::LEGAL_ENTITY;
    }

    public function label(): string
    {
        return match ($this) {
            self::INDIVIDUAL => 'حقیقی',
            self::LEGAL_ENTITY => 'حقوقی',
        };
    }
}
