<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain;

/**
 * When a trade must settle.
 *
 * The full set is the trades ENUM of docs/04-data/02-schema-mysql.md §2.4; the
 * instruments table in the same section lists only T0..T3, and this module's
 * migration widens that column to the union so one enum serves both tables.
 *
 * Risk takes this as a bare string (TradeIntent::$settlementType) because the
 * vocabulary belongs to Settlement, so `->value` is what crosses the boundary.
 */
enum SettlementType: string
{
    case INSTANT = 'INSTANT';
    case T0 = 'T0';
    case T1 = 'T1';
    case T2 = 'T2';
    case T3 = 'T3';
    case ON_ACCOUNT = 'ON_ACCOUNT';

    /** Business days between execution and the settlement deadline. */
    public function daysOffset(): int
    {
        return match ($this) {
            self::INSTANT, self::T0 => 0,
            self::T1 => 1,
            self::T2 => 2,
            self::T3 => 3,
            // Open account: no fixed deadline, treated as same-day for the
            // purposes of the trade record; Settlement applies the credit terms.
            self::ON_ACCOUNT => 0,
        };
    }

    public function isSameDay(): bool
    {
        return $this->daysOffset() === 0;
    }
}
