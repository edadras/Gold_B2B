<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Domain;

/**
 * When a settlement is due — docs/03-domain/05-settlement.md §5.1 and the
 * settlement_type column of docs/04-data/02-schema-mysql.md §2.5.
 *
 * INSTANT is the DvP pattern of §5.3 pattern 1 and is out of scope for phases
 * 1 and 2 (ADR-008: the platform does not hold member funds), so
 * requiresHeldFunds() exists to let callers reject it explicitly rather than
 * silently behave like T0.
 */
enum SettlementType: string
{
    case INSTANT = 'INSTANT';
    case T0 = 'T0';
    case T1 = 'T1';
    case T2 = 'T2';
    case T3 = 'T3';
    case ON_ACCOUNT = 'ON_ACCOUNT';

    /** Business days added to the trade date before the deadline falls. */
    public function offsetDays(): int
    {
        return match ($this) {
            self::INSTANT, self::T0, self::ON_ACCOUNT => 0,
            self::T1 => 1,
            self::T2 => 2,
            self::T3 => 3,
        };
    }

    /** Only possible if the system holds the cash — see ADR-008. */
    public function requiresHeldFunds(): bool
    {
        return $this === self::INSTANT;
    }

    /** §5.3 pattern 5: the obligation accrues and is netted at period end. */
    public function isOpenAccount(): bool
    {
        return $this === self::ON_ACCOUNT;
    }

    public function label(): string
    {
        return match ($this) {
            self::INSTANT => 'آنی',
            self::T0 => 'همان روز',
            self::T1 => 'یک روز کاری',
            self::T2 => 'دو روز کاری',
            self::T3 => 'سه روز کاری',
            self::ON_ACCOUNT => 'حساب باز',
        };
    }
}
