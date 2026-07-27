<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Events;

use App\Modules\Broadcasting\Contracts\ThrottledBroadcast;
use App\Modules\Broadcasting\Domain\BroadcastEventName;
use App\Modules\Broadcasting\Domain\ChannelName;
use App\Modules\Broadcasting\Domain\Payload;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

/**
 * `quote.updated` on the PUBLIC channel `market.{instrument}` — §3.3.
 *
 * PUBLIC MEANS PUBLIC. Anybody holding the app key can subscribe to this
 * channel without authenticating, so the payload is an allow-list of top-of-
 * book numbers and carries no organisation id, no member name, no order id and
 * no user id. There is nothing here from which a counterparty can be inferred
 * — see PublicPayloadPrivacyTest, which asserts it on the serialised array
 * rather than trusting this comment.
 *
 * Throttled to 5/sec per instrument (§3.5): it implements ThrottledBroadcast
 * and BroadcastGateway acts on that. A dropped quote is superseded within
 * 200ms, and the client re-reads truth over REST after any reconnect (§3.4).
 */
final class QuoteUpdated implements ShouldBroadcast, ThrottledBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly string $instrumentCode,
        public readonly ?int $bestBidRial = null,
        public readonly ?int $bestBidQtyMg = null,
        public readonly ?int $bestAskRial = null,
        public readonly ?int $bestAskQtyMg = null,
        public readonly ?int $lastPriceRial = null,
        public readonly ?int $dayChangeBps = null,
        public readonly ?string $timestamp = null,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new Channel(ChannelName::market($this->instrumentCode))];
    }

    public function broadcastAs(): string
    {
        return BroadcastEventName::QUOTE_UPDATED->value;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return Payload::compact([
            'instrument' => $this->instrumentCode,
            'best_bid' => $this->bestBidRial,
            'best_bid_qty_mg' => $this->bestBidQtyMg,
            'best_ask' => $this->bestAskRial,
            'best_ask_qty_mg' => $this->bestAskQtyMg,
            'last_price' => $this->lastPriceRial,
            'spread' => $this->spread(),
            'day_change_bps' => $this->dayChangeBps,
            'timestamp' => $this->timestamp,
        ]);
    }

    /** Integer subtraction only — both sides are int rial (AGENT_BRIEF rule 1). */
    private function spread(): ?int
    {
        if ($this->bestBidRial === null || $this->bestAskRial === null) {
            return null;
        }

        return $this->bestAskRial - $this->bestBidRial;
    }

    public function throttleEvent(): BroadcastEventName
    {
        return BroadcastEventName::QUOTE_UPDATED;
    }

    public function throttleSubject(): string
    {
        return $this->instrumentCode;
    }
}
