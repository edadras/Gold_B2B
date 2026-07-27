<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain;

/**
 * How the metal actually changes hands.
 *
 * Values match the ENUM in docs/04-data/02-schema-mysql.md §2.4. In worked
 * example 1 the delivery is a CUSTODY_CHANGE: the bar never moves, only the
 * owner_organization_id on the lot changes.
 */
enum DeliveryType: string
{
    /** Between two accounts inside the same vault. */
    case VAULT_TRANSFER = 'VAULT_TRANSFER';
    /** The buyer takes physical possession. */
    case PHYSICAL_HANDOVER = 'PHYSICAL_HANDOVER';
    /** Ownership changes, custody does not — the default for T0 book trades. */
    case CUSTODY_CHANGE = 'CUSTODY_CHANGE';
    /** Absorbed into an end-of-day netting batch. */
    case NETTED = 'NETTED';

    public function movesMetal(): bool
    {
        return $this === self::PHYSICAL_HANDOVER;
    }

    public function label(): string
    {
        return match ($this) {
            self::VAULT_TRANSFER => 'انتقال درون خزانه',
            self::PHYSICAL_HANDOVER => 'تحویل فیزیکی',
            self::CUSTODY_CHANGE => 'تغییر مالکیت',
            self::NETTED => 'تهاتر',
        };
    }
}
