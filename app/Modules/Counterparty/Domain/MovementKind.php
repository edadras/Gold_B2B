<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Domain;

/**
 * What produced a movement on a relation.
 *
 * Only trades and settlements move the relationship *statistics*; a manual
 * adjustment or an opening balance moves the money without pretending a trade
 * happened, otherwise `total_trade_count` becomes a lie the moment operations
 * fixes a mis-keyed figure.
 */
enum MovementKind: string
{
    case TRADE = 'TRADE';
    case SETTLEMENT = 'SETTLEMENT';
    case ADJUSTMENT = 'ADJUSTMENT';
    case OPENING = 'OPENING';
    case NETTING = 'NETTING';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function countsAsTrade(): bool
    {
        return $this === self::TRADE || $this === self::SETTLEMENT;
    }

    public function label(): string
    {
        return match ($this) {
            self::TRADE => 'معامله',
            self::SETTLEMENT => 'تسویه',
            self::ADJUSTMENT => 'تعدیل',
            self::OPENING => 'مانده ابتدای دوره',
            self::NETTING => 'تهاتر',
        };
    }
}
