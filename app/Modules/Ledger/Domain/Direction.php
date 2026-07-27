<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

use InvalidArgumentException;

/**
 * Accounting direction, derived from the sign of the amount and stored
 * redundantly so reports can group without arithmetic.
 */
enum Direction: string
{
    case CREDIT = 'CREDIT';
    case DEBIT = 'DEBIT';

    public static function ofAmount(int $amount): self
    {
        if ($amount === 0) {
            throw new InvalidArgumentException('A ledger entry amount cannot be zero');
        }

        return $amount > 0 ? self::CREDIT : self::DEBIT;
    }
}
