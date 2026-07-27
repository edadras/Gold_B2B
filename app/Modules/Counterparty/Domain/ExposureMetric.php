<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Domain;

/**
 * Which side of the bilateral balance a concentration or headroom figure is about.
 *
 * Gold and rial exposure are not interchangeable: a member can be heavily
 * concentrated in gold receivable while its rial book is flat, and netting the
 * two would require a conversion rate both sides agreed on (§10.8).
 */
enum ExposureMetric: string
{
    case GOLD = 'GOLD';
    case RIAL = 'RIAL';

    public function column(): string
    {
        return match ($this) {
            self::GOLD => 'gold_balance_mg',
            self::RIAL => 'rial_balance',
        };
    }

    public function limitColumn(): string
    {
        return match ($this) {
            self::GOLD => 'gold_credit_limit_mg',
            self::RIAL => 'rial_credit_limit',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GOLD => 'طلا',
            self::RIAL => 'ریال',
        };
    }
}
