<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Domain;

/**
 * The `event` field a client switches on, plus the throttling policy of
 * docs/05-api/03-realtime-webhooks.md §3.5.
 *
 * The policy lives on the name rather than in the throttle so that "is this
 * event allowed to be dropped?" has exactly one answer, and so that adding an
 * event forces a deliberate decision about it.
 */
enum BroadcastEventName: string
{
    case QUOTE_UPDATED = 'quote.updated';
    case DEPTH_UPDATED = 'depth.updated';
    case TRADE_EXECUTED = 'trade.executed';
    case MARKET_STATUS_CHANGED = 'market.status_changed';
    case REFERENCE_PRICE_UPDATED = 'reference_price.updated';
    case ORDER_UPDATED = 'order.updated';
    case BALANCE_UPDATED = 'balance.updated';
    case SETTLEMENT_STATUS_CHANGED = 'settlement.status_changed';
    case NOTIFICATION_CREATED = 'notification.created';

    /**
     * Maximum broadcasts per second, or null for "never dropped".
     *
     * `trade.executed` and `balance.updated` are deliberately null: a trader
     * who misses a fill or a balance move is looking at a screen that lies,
     * and the WebSocket is the only thing telling them in real time. A dropped
     * depth frame, by contrast, is superseded 100ms later.
     */
    public function maxPerSecond(): ?int
    {
        return match ($this) {
            self::DEPTH_UPDATED => 10,
            self::QUOTE_UPDATED => 5,
            default => null,
        };
    }

    public function isThrottled(): bool
    {
        return $this->maxPerSecond() !== null;
    }
}
