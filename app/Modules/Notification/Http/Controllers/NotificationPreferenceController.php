<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Modules\Notification\Application\ChannelPreferences;
use App\Modules\Notification\Application\PreferenceService;
use App\Modules\Notification\Http\Requests\UpdatePreferencesRequest;
use App\Modules\Notification\Http\Resources\NotificationPreferenceResource;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/notifications/preferences` — §2.14.
 *
 * AUTHORISATION: as with the feed, a preference row is USER-scoped. Both legs
 * are still checked, they are just narrower than usual: `auth:sanctum` settles
 * "who is calling", and every call below passes the caller's own user id, so
 * the tenant leg is subsumed by a stricter one. A `permit()` against an
 * organisation permission would be the wrong question — it would let an OWNER
 * rewrite a colleague's quiet hours, and no role in the RBAC matrix is meant to
 * carry that. The caller's user id is taken from the token, never from the
 * request body, so there is nothing here for a caller to substitute.
 */
final class NotificationPreferenceController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly PreferenceService $preferences,
    ) {
        parent::__construct($authorization);
    }

    public function index(Request $request): JsonResponse
    {
        $all = $this->preferences->all($this->userId($request));

        return ApiResponse::collection(
            NotificationPreferenceResource::collection(array_values($all)),
        );
    }

    /**
     * With `category`, one category changes; without it, all five do — the
     * "mute everything" switch of §15.3.
     */
    public function update(UpdatePreferencesRequest $request): JsonResponse
    {
        $userId = $this->userId($request);
        $category = $request->category();
        $settings = $request->settings();

        if ($category === null) {
            $this->preferences->setAll($userId, $settings);
        } else {
            $this->preferences->set($userId, $category, $settings);
        }

        // Re-read rather than echo the request: the stored answer includes the
        // defaults for anything the client did not send.
        $this->preferences->flushCache();

        $all = $this->preferences->all($userId);

        $rendered = $category === null
            ? array_values($all)
            : [$all[$category->value] ?? new ChannelPreferences($userId, $category)];

        return ApiResponse::collection(NotificationPreferenceResource::collection($rendered));
    }
}
