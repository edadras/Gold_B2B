<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

enum BalanceSide: string
{
    case DEBIT = 'DEBIT';
    case CREDIT = 'CREDIT';

    public function label(): string
    {
        return match ($this) {
            self::DEBIT => 'بد',
            self::CREDIT => 'بس',
        };
    }

    public function opposite(): self
    {
        return match ($this) {
            self::DEBIT => self::CREDIT,
            self::CREDIT => self::DEBIT,
        };
    }
}
