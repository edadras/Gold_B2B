<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/**
 * The batch auction at the open (or after a circuit-breaker pause) has run and
 * printed its trades — docs/03-domain/04-trading.md §4.8.
 *
 * Carries the one number the auction exists to produce: the single clearing
 * price every fill printed at. Members and surveillance both need it, and it
 * cannot be recovered from the individual TradeExecuted events without
 * assuming they all shared a price — which is the property this event asserts.
 *
 * Fired only when something actually crossed; an open with an empty or
 * non-crossing book has no clearing price to announce.
 */
final readonly class OpeningAuctionCompleted
{
    public function __construct(
        public int $instrumentId,
        public int $sessionId,
        public int $clearingPriceRial,
        public int $matchedVolumeMg,
        public int $orderCount,
        public int $tradeCount,
        public bool $resumedFromPause,
        public string $occurredAt,
    ) {}
}
