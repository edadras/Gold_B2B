<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Contracts;

/**
 * Settlement's window onto Trading.
 *
 * Trading is being built concurrently, so Settlement reacts to its
 * TradeExecuted event by string name and reads scalars defensively. This
 * interface covers the remaining case: re-opening a settlement for a trade
 * whose event was missed, or filling in a field the event did not carry.
 *
 * NullTradeReader is bound by default and returns null for everything, which
 * makes "I could not find the trade" an ordinary, testable outcome rather than
 * a hard dependency on a module that may not exist yet.
 */
interface TradeReaderInterface
{
    public function find(int $tradeId): ?TradeSnapshot;

    /** True when the trade exists and is in a state that may be settled. */
    public function isSettleable(int $tradeId): bool;
}
