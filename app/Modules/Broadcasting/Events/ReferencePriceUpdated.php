<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Events;

use App\Modules\Broadcasting\Domain\BroadcastEventName;
use App\Modules\Broadcasting\Domain\ChannelName;
use App\Modules\Broadcasting\Domain\Payload;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

/**
 * `reference_price.updated` on the public `reference-price` channel — §3.2
 * («قیمت مرجع و اونس/ارز»).
 *
 * `price_type` distinguishes the ounce, the FX rate and the derived domestic
 * reference; `value` is the raw accepted tick and `effective_value` the one
 * after the smoothing of §7.4, because a client charting the feed wants to see
 * both. The price source id is NOT published: which vendor the platform reads
 * is operational information, not market data.
 */
final class ReferencePriceUpdated implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly string $priceType,
        public readonly int $valueRial,
        public readonly ?int $effectiveValueRial = null,
        public readonly ?string $observedAt = null,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new Channel(ChannelName::REFERENCE_PRICE)];
    }

    public function broadcastAs(): string
    {
        return BroadcastEventName::REFERENCE_PRICE_UPDATED->value;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return Payload::compact([
            'price_type' => $this->priceType,
            'value' => $this->valueRial,
            'effective_value' => $this->effectiveValueRial,
            'observed_at' => $this->observedAt,
        ]);
    }
}
