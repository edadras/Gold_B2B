<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Domain;

/**
 * How the rial reaches the seller — docs/03-domain/05-settlement.md §5.1.
 *
 * BANK_TRANSFER is the phase 1 and 2 default: the money moves outside the
 * platform and the two parties assert that it did (ADR-008). INTERNAL would
 * require the platform to hold funds and is therefore phase 3+.
 */
enum PaymentMethod: string
{
    case BANK_TRANSFER = 'BANK_TRANSFER';
    case INTERNAL = 'INTERNAL';
    case NETTED = 'NETTED';

    /** Needs the two-sided declare/confirm handshake of §5.3 pattern 2. */
    public function requiresTwoSidedConfirmation(): bool
    {
        return $this === self::BANK_TRANSFER;
    }

    /** Only possible once the platform custodies cash — out of scope in phase 1. */
    public function requiresHeldFunds(): bool
    {
        return $this === self::INTERNAL;
    }

    public function isNetted(): bool
    {
        return $this === self::NETTED;
    }

    public function label(): string
    {
        return match ($this) {
            self::BANK_TRANSFER => 'انتقال بانکی',
            self::INTERNAL => 'داخلی',
            self::NETTED => 'تهاترشده',
        };
    }
}
