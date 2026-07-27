<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

/**
 * Trading pushes market data in through this interface; Pricing owns the
 * market_quotes table.
 */
interface QuoteWriterInterface
{
    /** Fold one executed trade into the day's statistics. */
    public function recordTrade(TradePrint $print): void;

    /** Replace the top of book. Nulls mean "no resting order on that side". */
    public function updateTopOfBook(
        int $instrumentId,
        ?int $bestBidRial,
        ?int $bestBidQtyMg,
        ?int $bestAskRial,
        ?int $bestAskQtyMg,
    ): void;
}
