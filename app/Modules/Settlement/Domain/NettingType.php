<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Domain;

/**
 * Bilateral (F12) or multilateral (F13) netting —
 * docs/11-appendix/01-formulas.md §1.6, docs/03-domain/05-settlement.md §5.6.
 *
 * Multilateral routes every flow through the CLEARING system account, which
 * means the clearing house takes on everyone's credit risk. §5.6 restricts it
 * to phase 3 and makes it conditional on legal sign-off and guarantee capital;
 * requiresClearingAccount() is what tells the executor to use CLEARING.
 */
enum NettingType: string
{
    case BILATERAL = 'BILATERAL';
    case MULTILATERAL = 'MULTILATERAL';

    /** Multilateral flows pass through SYSTEM/CLEARING; bilateral ones do not. */
    public function requiresClearingAccount(): bool
    {
        return $this === self::MULTILATERAL;
    }

    public function label(): string
    {
        return match ($this) {
            self::BILATERAL => 'تهاتر دوطرفه',
            self::MULTILATERAL => 'تهاتر چندطرفه',
        };
    }
}
