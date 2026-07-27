<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Events;

use App\Modules\Broadcasting\Contracts\ThrottledBroadcast;
use App\Modules\Broadcasting\Domain\BroadcastEventName;
use App\Modules\Broadcasting\Domain\ChannelName;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

/**
 * `depth.updated` on the PUBLIC channel `market.{instrument}` — §3.3.
 *
 * A ladder level is `[price_rial, quantity_mg, order_count]`. `order_count` is
 * a COUNT, never a list of ids: on a thin book "1 order at 78,420,000" is
 * already close to identifying, and an id would make it certain. Nothing else
 * about the resting orders crosses to a public channel.
 *
 * The heaviest event on the platform and the most aggressively aggregated:
 * 10/sec per instrument, coalesced into 100ms windows (§3.5).
 */
final class DepthUpdated implements ShouldBroadcast, ThrottledBroadcast
{
    use SerializesModels;

    /**
     * @param  list<array{0: int, 1: int, 2: int}>  $bids  best first
     * @param  list<array{0: int, 1: int, 2: int}>  $asks  best first
     */
    public function __construct(
        public readonly string $instrumentCode,
        public readonly array $bids,
        public readonly array $asks,
        public readonly ?string $timestamp = null,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new Channel(ChannelName::market($this->instrumentCode))];
    }

    public function broadcastAs(): string
    {
        return BroadcastEventName::DEPTH_UPDATED->value;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'instrument' => $this->instrumentCode,
            'bids' => $this->levels($this->bids),
            'asks' => $this->levels($this->asks),
            'timestamp' => $this->timestamp ?? now()->toIso8601ZuluString('millisecond'),
        ];
    }

    /**
     * Normalises to exactly three integers per level, discarding anything else
     * a caller may have attached. A defensive narrowing, not a formality: this
     * is the last place before a public socket.
     *
     * @param  array<int, mixed>  $levels
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private function levels(array $levels): array
    {
        $clean = [];

        foreach ($levels as $level) {
            if (! is_array($level)) {
                continue;
            }

            $level = array_values($level);

            if (count($level) < 2 || ! is_int($level[0]) || ! is_int($level[1])) {
                continue;
            }

            $clean[] = [$level[0], $level[1], isset($level[2]) && is_int($level[2]) ? $level[2] : 1];
        }

        return $clean;
    }

    public function throttleEvent(): BroadcastEventName
    {
        return BroadcastEventName::DEPTH_UPDATED;
    }

    public function throttleSubject(): string
    {
        return $this->instrumentCode;
    }
}
