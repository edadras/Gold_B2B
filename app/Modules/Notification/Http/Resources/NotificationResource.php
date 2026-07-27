<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Resources;

use App\Modules\Notification\Infrastructure\Notification;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * One row of the in-app feed — docs/05-api/02-endpoints.md §2.14.
 *
 * `aggregate_count` is exposed rather than hidden: a collapsed row means "7
 * orders were filled", and a client that cannot see the count would render it
 * as a single event and lose six of them.
 *
 * @mixin Notification
 */
final class NotificationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Notification $notification */
        $notification = $this->resource;

        $code = $notification->notificationCode();

        return [
            'id' => (int) $notification->id,
            'code' => (string) $notification->code,
            'category' => (string) $notification->category,
            'priority' => $notification->priority->value,
            'title' => (string) $notification->title,
            'body' => (string) $notification->body,
            'action_type' => $notification->action_type,
            'action_payload' => $notification->action_payload,
            'subject_type' => $notification->subject_type,
            'subject_id' => $notification->subject_id === null ? null : (int) $notification->subject_id,
            'is_aggregate' => $notification->isAggregate(),
            'aggregate_count' => $notification->aggregate_count === null
                ? null
                : (int) $notification->aggregate_count,
            'is_read' => $notification->read_at !== null,
            'read_at' => Display::iso($notification->read_at),
            'created_at' => Display::iso($notification->created_at),
        ] + $this->display($request, [
            'priority_display' => $notification->priority->label(),
            'category_display' => $code?->category()->label(),
            'created_at_jalali' => Display::jalali($notification->created_at),
            'read_at_jalali' => Display::jalali($notification->read_at),
        ]);
    }
}
