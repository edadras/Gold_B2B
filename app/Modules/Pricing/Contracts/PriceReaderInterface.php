<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use App\Modules\Shared\ValueObjects\PricePerFineGram;

/**
 * The only way other modules read prices. Trading and Risk depend on this
 * interface, never on Pricing's Eloquent models (AGENT_BRIEF rule 7).
 */
interface PriceReaderInterface
{
    /** Latest computed intrinsic value (F6), or null when none has been computed. */
    public function referencePrice(int $instrumentId): ?PricePerFineGram;

    /** Highest resting buy price in the order book. */
    public function bestBid(int $instrumentId): ?PricePerFineGram;

    /** Lowest resting sell price in the order book. */
    public function bestAsk(int $instrumentId): ?PricePerFineGram;

    /** Last ORDER_BOOK trade price — OTC prints never move this (§7.6). */
    public function lastPrice(int $instrumentId): ?PricePerFineGram;
}
