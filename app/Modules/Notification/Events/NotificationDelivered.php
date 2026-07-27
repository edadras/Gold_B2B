<?php

declare(strict_types=1);

namespace App\Modules\Notification\Events;

/**
 * One in-app notification row was written for one user.
 *
 * Fired per recipient, not per dispatch: a NotificationSpec resolves to a role
 * and a role is several people, and each of them has their own row, their own
 * id and their own private socket channel.
 *
 * It is fired only where the row is genuinely created — after deduplication
 * (§15.1 rule 4) and after batching (rule 3) have had their say. A suppressed
 * duplicate produces no event, so a listener cannot resurrect an alert the
 * catalogue decided not to send, and an aggregated one produces none either
 * until its window closes.
 *
 * Quiet hours and channel preferences deliberately do *not* gate it. Those
 * govern whether a phone buzzes; the in-app feed always receives everything,
 * and this event mirrors the feed.
 *
 * Broadcasting subscribes by string name to push it onto
 * `private-org.{orgId}.notification`. The rendered Persian text rides along for
 * consumers that want it, but the socket layer drops it — §3.5 forbids
 * display-ready strings on the wire.
 */
final readonly class NotificationDelivered
{
    public function __construct(
        public int $notificationId,
        public int $organizationId,
        public int $userId,
        public string $code,
        public string $category,
        public string $priority,
        public string $subject,
        public string $body,
        public ?string $subjectType,
        public ?int $subjectId,
        public string $occurredAt,
    ) {}
}
