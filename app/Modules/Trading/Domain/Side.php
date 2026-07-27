<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain;

/** Which way an order or a trade leg points. */
enum Side: string
{
    case BUY = 'BUY';
    case SELL = 'SELL';

    public function opposite(): self
    {
        return $this === self::BUY ? self::SELL : self::BUY;
    }

    public function isBuy(): bool
    {
        return $this === self::BUY;
    }

    public function isSell(): bool
    {
        return $this === self::SELL;
    }

    /**
     * Book ordering for the resting side an incoming order of THIS side will
     * match against: a buyer wants the cheapest ask first, a seller the dearest
     * bid first (docs/03-domain/04-trading.md §4.4).
     */
    public function makerPriceDirection(): string
    {
        return $this === self::BUY ? 'asc' : 'desc';
    }

    public function label(): string
    {
        return $this === self::BUY ? 'خرید' : 'فروش';
    }
}
