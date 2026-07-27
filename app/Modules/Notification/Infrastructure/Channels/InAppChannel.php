<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Channels;

use App\Modules\Notification\Contracts\DeliveryOutcome;
use App\Modules\Notification\Contracts\NotificationChannel;
use App\Modules\Notification\Contracts\OutboundMessage;
use App\Modules\Notification\Domain\Channel;
use App\Modules\Notification\Infrastructure\Notification;

/**
 * The in-app channel: it writes the `notifications` row.
 *
 * That row is both the feed entry and the anchor every external delivery
 * references, so this driver runs first and hands its id back in the outcome.
 * §15.1 gives In-App every notification, which is why quiet hours and channel
 * preferences never suppress it — they only silence the channels that make a
 * noise on somebody's phone.
 */
final class InAppChannel implements NotificationChannel
{
    public function channel(): Channel
    {
        return Channel::IN_APP;
    }

    public function send(OutboundMessage $message): DeliveryOutcome
    {
        $notification = new Notification;
        $notification->fill([
            'organization_id' => $message->organizationId,
            'user_id' => $message->userId,
            'code' => $message->code,
            'category' => $message->category,
            'priority' => $message->priority,
            'title' => mb_substr($message->title, 0, 200),
            'body' => mb_substr($message->body, 0, 1_000),
            'action_type' => $message->actionType,
            'action_payload' => $message->actionPayload,
            'subject_type' => $message->subjectType,
            'subject_id' => $message->subjectId,
            'aggregate_count' => $message->aggregateCount,
            'aggregate_window_start' => $message->aggregateCount !== null ? now() : null,
        ]);
        $notification->save();

        return DeliveryOutcome::sent(
            providerRef: 'in-app:'.$notification->id,
            notificationId: $notification->id,
        );
    }
}
