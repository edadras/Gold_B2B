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
 * Live notification delivery on `private-org.{orgId}.notification` — §3.2
 * («اعلان‌های زنده»), and additionally on `private-user.{userId}` when the
 * notification belongs to one person rather than to the whole desk.
 *
 * TWO CHANNELS, ONE EVENT, ON PURPOSE. The org notification channel is what an
 * always-on dealing-desk screen subscribes to; `private-user.{userId}` is what
 * the phone in one trader's pocket subscribes to. When a userId is present the
 * event lands on both and the client dedupes on `id` — which is why `id` is
 * required rather than optional.
 *
 * CODE FIRST, TEXT MAYBE. §3.5 says the socket carries no `_display` fields
 * and that the client formats for itself, so the contract here is `code` plus a
 * small scalar `data` map, which a Persian client renders and an English one
 * renders differently. `title`/`body` are optional and carry pre-rendered text
 * only when the producer already had it; a client must not require them.
 *
 * Not throttled (§3.5).
 */
final class NotificationDelivered implements ShouldBroadcast
{
    use SerializesModels;

    /** @param array<string, scalar|null> $data */
    public function __construct(
        public readonly int $organizationId,
        public readonly string $id,
        public readonly string $code,
        public readonly ?int $userId = null,
        public readonly ?string $title = null,
        public readonly ?string $body = null,
        public readonly ?string $category = null,
        public readonly ?string $priority = null,
        public readonly array $data = [],
        public readonly ?string $createdAt = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel(ChannelName::organizationNotification($this->organizationId)),
        ];

        if ($this->userId !== null) {
            $channels[] = new PrivateChannel(ChannelName::user($this->userId));
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return BroadcastEventName::NOTIFICATION_CREATED->value;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return Payload::compact([
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'body' => $this->body,
            'category' => $this->category,
            'priority' => $this->priority,
            'data' => $this->data === [] ? null : $this->data,
            'created_at' => $this->createdAt,
        ]);
    }
}
