<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Infrastructure\Null;

use App\Modules\Settlement\Contracts\TradeReaderInterface;
use App\Modules\Settlement\Contracts\TradeSnapshot;

/**
 * Default binding for TradeReaderInterface.
 *
 * Trading is being built concurrently and publishes no read contract yet.
 * Settlement's primary input is the TradeExecuted event, which carries every
 * figure it needs; this reader exists only for the secondary path (re-opening
 * a settlement for a trade whose event was missed), so reporting "not found"
 * is the honest answer rather than a failure.
 *
 * Deliberately not restrictive: isSettleable() returning false would make the
 * secondary path silently refuse work once Trading does arrive, so both
 * methods simply report absence.
 */
final class NullTradeReader implements TradeReaderInterface
{
    public function find(int $tradeId): ?TradeSnapshot
    {
        return null;
    }

    public function isSettleable(int $tradeId): bool
    {
        return false;
    }
}
