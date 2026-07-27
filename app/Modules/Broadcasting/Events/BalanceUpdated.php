<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Events;

use App\Modules\Broadcasting\Domain\BroadcastEventName;
use App\Modules\Broadcasting\Domain\ChannelName;
use App\Modules\Broadcasting\Domain\Payload;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

/**
 * `balance.updated` on `private-org.{orgId}` — §3.3.
 *
 * EXPLICITLY NOT THROTTLED (§3.5: «بدون throttle (مهم است)»). A member whose
 * available balance is stale places an order they cannot fund, or refuses one
 * they can. BroadcastEventName::BALANCE_UPDATED returns null from
 * maxPerSecond(), so PrivateBroadcaster has no code path that could drop it.
 *
 * Field names follow the document's `*_mg` spelling, which is written for the
 * GOLD asset. For asset_type RIAL the same four fields carry integer rial —
 * the unit is implied by asset_type, exactly as it is in the REST balance
 * resource, and renaming them per asset would break the client's parser.
 *
 * `trigger` and `reference` answer "why did this move?" without a second
 * round trip: `order.placed` / `ORD-00044120`.
 */
final class BalanceUpdated implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly int $organizationId,
        public readonly string $assetType,
        public readonly ?int $availableMg = null,
        public readonly ?int $reservedMg = null,
        public readonly ?int $inSettlementMg = null,
        public readonly ?int $totalMg = null,
        public readonly ?string $trigger = null,
        public readonly ?string $reference = null,
        public readonly ?string $timestamp = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(ChannelName::organization($this->organizationId))];
    }

    public function broadcastAs(): string
    {
        return BroadcastEventName::BALANCE_UPDATED->value;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return Payload::compact([
            'asset_type' => $this->assetType,
            'available_mg' => $this->availableMg,
            'reserved_mg' => $this->reservedMg,
            'in_settlement_mg' => $this->inSettlementMg,
            'total_mg' => $this->totalMg,
            'trigger' => $this->trigger,
            'reference' => $this->reference,
            'timestamp' => $this->timestamp,
        ]);
    }
}
