<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Modules\Notification\Application\NotificationFeedService;
use App\Modules\Notification\Http\Requests\ListNotificationsRequest;
use App\Modules\Notification\Http\Resources\NotificationResource;
use App\Modules\Notification\Infrastructure\Notification;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Support\Cursor;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/notifications` — docs/05-api/02-endpoints.md §2.14.
 *
 * AUTHORISATION NOTE — WHY THERE IS NO permit() CALL IN THIS CONTROLLER.
 *
 * Everywhere else in the API both legs are checked: the caller's role must
 * grant the permission AND the resource must belong to the caller's
 * organisation, because a tenant scope on its own is not enough — a raw query
 * or `withoutGlobalScope()` walks straight past an Eloquent global scope, so
 * tenancy has to be re-asserted explicitly at the boundary.
 *
 * NOTIFICATIONS ARE USER-SCOPED, NOT MERELY ORGANIZATION-SCOPED. A notification
 * is addressed to one person (`notifications.user_id`), and "same organisation"
 * is strictly weaker than "same user": an OWNER shares the caller's tenant and
 * still must not read, count or clear a colleague's feed. So the two legs here
 * are (1) authenticated — enforced by `auth:sanctum` — and (2) the caller's own
 * user id, which every query in NotificationFeedService is keyed on and which
 * this controller passes explicitly rather than trusting to a scope. Asking
 * `permit()` for an organisation-level permission would answer a broader
 * question than the one being decided and would let a colleague through.
 *
 * The same reason drives the 404s: a notification id that exists but belongs to
 * somebody else is reported as missing, never as forbidden, so this endpoint
 * cannot be used to probe the notification table.
 *
 * There is deliberately no role gate at all — every authenticated member,
 * including a VIEWER, must be able to read the alerts addressed to them.
 */
final class NotificationController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly NotificationFeedService $feed,
    ) {
        parent::__construct($authorization);
    }

    /**
     * Cursor-paginated feed, newest first.
     *
     * Limit, cursor decoding and the `links` block all go through
     * Shared\Http\Support\Cursor so that a cursor means the same thing on every
     * paginated endpoint of the platform (§1.8: base64 of `{"id": N}`, default
     * 50, maximum 200). `links.prev` is null by that helper's deliberate
     * design — pagination here is forward-only and the client keeps its own
     * history, which is better than advertising a link that cannot be honoured.
     */
    public function index(ListNotificationsRequest $request): JsonResponse
    {
        $limit = Cursor::limit($request);

        $notifications = $this->feed->feed(
            $this->userId($request),
            Cursor::afterId($request),
            $limit,
        );

        $ids = array_map(static fn (Notification $n): int => (int) $n->id, $notifications);

        return ApiResponse::collection(
            NotificationResource::collection($notifications),
            Cursor::links($request, $ids, $limit),
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->feed->unreadCount($this->userId($request));

        return ApiResponse::item([
            'unread_count' => $count,
        ] + (Display::enabledFor($request) ? ['unread_count_display' => Display::digits((string) $count)] : []));
    }

    /** A notification that is not the caller's is 404, never 403. */
    public function markRead(Request $request, int $notificationId): JsonResponse
    {
        $notification = $this->feed->markRead($this->userId($request), $notificationId);

        if ($notification === null) {
            throw $this->notFound();
        }

        return ApiResponse::item(new NotificationResource($notification));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $marked = $this->feed->markAllRead($this->userId($request));

        return ApiResponse::item(['marked_read' => $marked]);
    }
}
