<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain;

/**
 * Asset class of a manual adjustment. Mirrors Ledger's AssetType by value —
 * Admin may not import it (see the module dependency map) but the string is the
 * `ledger_accounts.asset_type` enum, so the values must agree.
 */
enum AdjustmentAsset: string
{
    case GOLD = 'GOLD';
    case RIAL = 'RIAL';

    public function label(): string
    {
        return match ($this) {
            self::GOLD => 'طلا',
            self::RIAL => 'ریال',
        };
    }

    /** Smallest unit, for the amount hint on the form. */
    public function unitLabel(): string
    {
        return match ($this) {
            self::GOLD => 'میلی‌گرم خالص',
            self::RIAL => 'ریال',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
