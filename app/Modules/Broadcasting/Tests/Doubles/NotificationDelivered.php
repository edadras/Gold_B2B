<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests\Doubles;

/**
 * Stand-in for App\Modules\Notification\Events\NotificationDelivered — the
 * event the Notification module fires for each in-app row it writes.
 *
 * Not to be confused with App\Modules\Broadcasting\Events\NotificationDelivered,
 * which is this module's outgoing socket frame. Same name, opposite direction:
 * that one is what the listener produces, this is what it consumes.
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
        public ?string $subjectType = null,
        public ?int $subjectId = null,
        public string $occurredAt = '',
    ) {}
}
