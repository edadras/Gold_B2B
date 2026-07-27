<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

/**
 * The five notification categories of §15.2.
 *
 * The category is what a user's preferences are keyed on: nobody wants to
 * configure forty-five codes, and "mute asset movements but keep settlement
 * alerts" is the granularity people actually think in.
 */
enum Category: string
{
    case TRADING = 'TRADING';
    case SETTLEMENT = 'SETTLEMENT';
    case ASSET = 'ASSET';
    case ACCOUNT = 'ACCOUNT';
    case DISPUTE = 'DISPUTE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::TRADING => 'معاملات',
            self::SETTLEMENT => 'تسویه',
            self::ASSET => 'دارایی',
            self::ACCOUNT => 'حساب و انطباق',
            self::DISPUTE => 'اختلاف',
        };
    }
}
