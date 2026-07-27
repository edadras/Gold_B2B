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
 * `settlement.status_changed` on `private-org.{orgId}.settlement` — §3.3.
 *
 * `requires_your_action` is per RECIPIENT, not per settlement: the payer sees
 * true and CONFIRM_PAYMENT while the payee sees false on the same transition,
 * so the listener builds one instance per side. That is the whole reason the
 * settlement channel is separate from `private-org.{orgId}` — a settlement
 * feed is a to-do list, and a to-do list is personal.
 *
 * Not throttled: every transition of a deadline-bearing obligation goes out.
 */
final class SettlementStatusChanged implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $settlementId,
        public readonly ?string $settlementCode,
        public readonly ?string $fromStatus,
        public readonly string $toStatus,
        public readonly bool $requiresYourAction = false,
        public readonly ?string $actionType = null,
        public readonly ?string $deadlineAt = null,
        public readonly ?string $paymentReference = null,
        public readonly ?string $timestamp = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(ChannelName::organizationSettlement($this->organizationId))];
    }

    public function broadcastAs(): string
    {
        return BroadcastEventName::SETTLEMENT_STATUS_CHANGED->value;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return Payload::compact([
            // `settlement_id` is additional to §3.3, which shows only the code.
            // Half the Settlement domain events carry the id and not the code
            // (the code is derived from the TRADE id, so it cannot be rebuilt
            // from the settlement id), and a client that cannot key the frame
            // to a row can do nothing with it. The code is still sent whenever
            // the producing event knew it.
            'settlement_id' => $this->settlementId,
            'settlement_code' => $this->settlementCode,
            'from_status' => $this->fromStatus,
            'to_status' => $this->toStatus,
            // Kept even when false — "nothing to do" is information the client
            // uses to clear a badge, so Payload::compact only drops nulls.
            'requires_your_action' => $this->requiresYourAction,
            'action_type' => $this->actionType,
            'deadline_at' => $this->deadlineAt,
            'payment_reference' => $this->paymentReference,
            'timestamp' => $this->timestamp,
        ]);
    }
}
