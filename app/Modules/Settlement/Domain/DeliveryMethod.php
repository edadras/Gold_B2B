<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Domain;

/**
 * How the gold reaches the buyer — docs/03-domain/05-settlement.md §5.1/§5.3.
 *
 * CUSTODY_CHANGE (pattern 4) is the reason the custody layer exists: the metal
 * stays in the same box on the same shelf and only owner_organization_id
 * changes. Zero transport cost, zero transport risk.
 */
enum DeliveryMethod: string
{
    case VAULT_TRANSFER = 'VAULT_TRANSFER';
    case PHYSICAL_HANDOVER = 'PHYSICAL_HANDOVER';
    case CUSTODY_CHANGE = 'CUSTODY_CHANGE';
    case NETTED = 'NETTED';

    /** True when metal actually leaves its shelf. */
    public function movesMetal(): bool
    {
        return $this === self::VAULT_TRANSFER || $this === self::PHYSICAL_HANDOVER;
    }

    /** §5.3 pattern 3: a one-time code the receiver enters to confirm delivery. */
    public function requiresHandoverCode(): bool
    {
        return $this === self::PHYSICAL_HANDOVER;
    }

    /** Delivery is discharged by a netting batch, not by a lot movement. */
    public function isNetted(): bool
    {
        return $this === self::NETTED;
    }

    public function label(): string
    {
        return match ($this) {
            self::VAULT_TRANSFER => 'انتقال بین خزانه',
            self::PHYSICAL_HANDOVER => 'تحویل فیزیکی',
            self::CUSTODY_CHANGE => 'تغییر نگهدارنده',
            self::NETTED => 'تهاترشده',
        };
    }
}
