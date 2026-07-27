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
 * `order.updated` on `private-org.{orgId}` — §3.3.
 *
 * One event covers placed / partially filled / filled / cancelled / rejected /
 * expired: the client re-renders the row from `status` plus the two quantities
 * and does not need six event names to do it. `last_fill` is present only on
 * the transitions that produced one.
 *
 * Not throttled. An order's terminal state arriving late is a client that
 * shows a resting order which no longer exists.
 */
final class OrderUpdated implements ShouldBroadcast
{
    use SerializesModels;

    /** @param array{trade_code?: string, quantity_mg?: int, price_rial?: int}|null $lastFill */
    public function __construct(
        public readonly int $organizationId,
        public readonly int $orderId,
        public readonly string $status,
        public readonly ?string $orderCode = null,
        public readonly ?int $filledMg = null,
        public readonly ?int $remainingMg = null,
        public readonly ?string $side = null,
        public readonly ?array $lastFill = null,
        public readonly ?string $reason = null,
        public readonly ?string $timestamp = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(ChannelName::organization($this->organizationId))];
    }

    public function broadcastAs(): string
    {
        return BroadcastEventName::ORDER_UPDATED->value;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return Payload::compact([
            'id' => $this->orderId,
            'order_code' => $this->orderCode,
            'status' => $this->status,
            'side' => $this->side,
            'filled_mg' => $this->filledMg,
            'remaining_mg' => $this->remainingMg,
            'last_fill' => $this->lastFill,
            'reason' => $this->reason,
            'timestamp' => $this->timestamp,
        ]);
    }
}
