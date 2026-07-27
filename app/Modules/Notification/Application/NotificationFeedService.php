<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Modules\Notification\Infrastructure\Notification;
use Illuminate\Support\Carbon;

/**
 * Reading the in-app feed: list, count, mark read.
 *
 * The module shipped with a dispatcher that writes notifications and nothing at
 * all that reads them back, so this service exists to keep the HTTP layer thin
 * — a controller must not run an Eloquent query.
 *
 * EVERYTHING HERE IS SCOPED TO ONE USER, NOT ONE ORGANISATION. A notification
 * is addressed to a person: `notifications.user_id` is the owner, and an OWNER
 * may not read, count or clear a colleague's feed even though the two share an
 * organisation. That is why no method takes an organisation id, and why a row
 * belonging to somebody else is reported as missing rather than forbidden.
 *
 * Cursor decoding and the `links` block belong to
 * App\Modules\Shared\Http\Support\Cursor, the platform-wide implementation of
 * conventions §1.8; this service only takes the decoded `id` and applies it.
 */
final class NotificationFeedService
{
    /**
     * A page of the caller's feed, newest first.
     *
     * The cursor is keyed on `id`, not on `created_at`: §1.8 specifies
     * `base64({"id": N})`, ids are monotonic with insertion, and a timestamp
     * cursor would skip or repeat rows whenever two notifications share a
     * second. `feedFor` orders by `created_at`, so the ordering is replaced
     * here to keep the cursor and the sort key the same column — a cursor that
     * does not match the sort is a feed that silently loses rows.
     *
     * @param  int|null  $afterId  return rows OLDER than this id
     * @return array<int, Notification>
     */
    public function feed(int $userId, ?int $afterId = null, int $limit = 50): array
    {
        $query = Notification::query()
            ->feedFor($userId)
            ->reorder('id', 'desc');

        if ($afterId !== null) {
            $query->where('id', '<', $afterId);
        }

        return $query->limit($limit)->get()->all();
    }

    /** How many unread rows the feed would show — the badge on the bell. */
    public function unreadCount(int $userId): int
    {
        return Notification::query()
            ->feedFor($userId)
            ->reorder()
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Mark one notification read.
     *
     * Returns null when the id does not exist OR belongs to another user; the
     * caller cannot tell the two apart, which stops the endpoint being used to
     * walk the notification table.
     */
    public function markRead(int $userId, int $notificationId): ?Notification
    {
        /** @var Notification|null $notification */
        $notification = Notification::query()
            ->whereKey($notificationId)
            ->where('user_id', $userId)
            ->first();

        $notification?->markRead();

        return $notification;
    }

    /** @return int how many rows were newly marked read */
    public function markAllRead(int $userId): int
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);
    }
}
