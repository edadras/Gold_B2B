<?php

declare(strict_types=1);

namespace App\Modules\Notification\Contracts;

use App\Modules\Notification\Domain\Channel;

/**
 * A rendered notification on its way to one destination.
 *
 * Everything a driver could need, already resolved: no driver ever queries the
 * database, looks up a preference or re-renders a template, so swapping the
 * local log driver for a real FCM client is a change to one class.
 */
final readonly class OutboundMessage
{
    /**
     * @param  array<string, mixed>|null  $actionPayload
     */
    public function __construct(
        public int $organizationId,
        public ?int $userId,
        public string $code,
        public string $category,
        public string $priority,
        public string $title,
        public string $body,
        public string $destination,
        public ?int $notificationId = null,
        public ?string $actionType = null,
        public ?array $actionPayload = null,
        public ?string $subjectType = null,
        public ?int $subjectId = null,
        public ?int $aggregateCount = null,
    ) {}

    public function withNotificationId(int $notificationId): self
    {
        return new self(
            organizationId: $this->organizationId,
            userId: $this->userId,
            code: $this->code,
            category: $this->category,
            priority: $this->priority,
            title: $this->title,
            body: $this->body,
            destination: $this->destination,
            notificationId: $notificationId,
            actionType: $this->actionType,
            actionPayload: $this->actionPayload,
            subjectType: $this->subjectType,
            subjectId: $this->subjectId,
            aggregateCount: $this->aggregateCount,
        );
    }

    public function withDestination(string $destination): self
    {
        return new self(
            organizationId: $this->organizationId,
            userId: $this->userId,
            code: $this->code,
            category: $this->category,
            priority: $this->priority,
            title: $this->title,
            body: $this->body,
            destination: $destination,
            notificationId: $this->notificationId,
            actionType: $this->actionType,
            actionPayload: $this->actionPayload,
            subjectType: $this->subjectType,
            subjectId: $this->subjectId,
            aggregateCount: $this->aggregateCount,
        );
    }

    /**
     * The destination as it is safe to store: §15.3 keeps a hash, because
     * support staff read this table during incident triage and a member's
     * mobile number has no business being visible there. The prefix keeps the
     * value debuggable — you can still tell an SMS row from a push row.
     */
    public static function hashDestination(Channel $channel, string $destination): string
    {
        return strtolower($channel->value).':'.substr(hash('sha256', $destination), 0, 32);
    }
}
