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
 * `market.status_changed` on the public `market.status` channel — §3.2
 * («وضعیت جلسه معاملاتی، توقف بازار»).
 *
 * Session opened / closed / paused / resumed, and the circuit breaker. Public,
 * so the instrument code is the only identifier present; the session id is an
 * internal key and is not published.
 *
 * Never throttled — a halt that arrives late is a halt the trader traded
 * through.
 */
final class MarketStatusChanged implements ShouldBroadcast
{
    use SerializesModels;

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_PAUSED = 'PAUSED';

    public function __construct(
        public readonly ?string $instrumentCode,
        public readonly string $status,
        public readonly ?string $reasonCode = null,
        public readonly ?string $reason = null,
        public readonly ?string $resumeAt = null,
        public readonly ?string $sessionDate = null,
        public readonly ?int $referencePriceRial = null,
        public readonly ?string $timestamp = null,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new Channel(ChannelName::MARKET_STATUS)];
    }

    public function broadcastAs(): string
    {
        return BroadcastEventName::MARKET_STATUS_CHANGED->value;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return Payload::compact([
            'instrument' => $this->instrumentCode,
            'status' => $this->status,
            'reason_code' => $this->reasonCode,
            'reason' => $this->reason,
            'resume_at' => $this->resumeAt,
            'session_date' => $this->sessionDate,
            'reference_price_rial' => $this->referencePriceRial,
            'timestamp' => $this->timestamp ?? now()->toIso8601ZuluString('millisecond'),
        ]);
    }
}
