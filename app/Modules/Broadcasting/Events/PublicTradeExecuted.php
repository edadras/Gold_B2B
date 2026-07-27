<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Events;

use App\Modules\Broadcasting\Domain\BroadcastEventName;
use App\Modules\Broadcasting\Domain\ChannelName;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

/**
 * `trade.executed` on the PUBLIC channel `market.{instrument}` — the tape.
 *
 * §3.3: «بدون هویت طرفین» — no party identity. Four fields and a timestamp,
 * and the constructor takes nothing else, so there is no organisation id in
 * scope to leak by accident. The counterparty-bearing version of this event is
 * a separate class (PrivateTradeExecuted) on a separate, authorised channel;
 * they are not two modes of one object, because a boolean flag on one object
 * is one wrong branch away from publishing a member's name to the world.
 *
 * NOT throttled (§3.5). Every print goes out.
 */
final class PublicTradeExecuted implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly string $instrumentCode,
        public readonly int $priceRial,
        public readonly int $quantityMg,
        public readonly ?string $takerSide,
        public readonly string $executedAt,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new Channel(ChannelName::market($this->instrumentCode))];
    }

    public function broadcastAs(): string
    {
        return BroadcastEventName::TRADE_EXECUTED->value;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return array_filter([
            'instrument' => $this->instrumentCode,
            'price_rial' => $this->priceRial,
            'quantity_mg' => $this->quantityMg,
            'taker_side' => $this->takerSide,
            'executed_at' => $this->executedAt,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
