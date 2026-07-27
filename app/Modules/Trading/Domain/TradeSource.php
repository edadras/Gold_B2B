<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain;

/**
 * Which channel produced a trade.
 *
 * Only ORDER_BOOK prints move the last price and appear on the public tape;
 * OTC prints are private and contribute to VWAP only
 * (docs/03-domain/04-trading.md §4.6 table, and §7.6 of the pricing document).
 *
 * Mirrors Pricing\Contracts\TradeSource, which is that module's copy of the
 * same vocabulary; TradePrint conversion maps between them by value.
 */
enum TradeSource: string
{
    case ORDER_BOOK = 'ORDER_BOOK';
    case OTC = 'OTC';
    case RFQ = 'RFQ';

    public function movesLastPrice(): bool
    {
        return $this === self::ORDER_BOOK;
    }

    public function appearsOnPublicTape(): bool
    {
        return $this === self::ORDER_BOOK;
    }
}
