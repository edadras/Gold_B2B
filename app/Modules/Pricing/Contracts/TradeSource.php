<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

/**
 * Where a print came from. Only ORDER_BOOK prints may move LAST or build a
 * candle; VWAP and volume include everything (docs/03-domain/07-pricing.md §7.6).
 */
enum TradeSource: string
{
    case ORDER_BOOK = 'ORDER_BOOK';
    case OTC = 'OTC';
    case RFQ = 'RFQ';
    case NETTING = 'NETTING';

    public function movesLastPrice(): bool
    {
        return $this === self::ORDER_BOOK;
    }

    public function buildsCandles(): bool
    {
        return $this === self::ORDER_BOOK;
    }
}
