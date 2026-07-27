<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Http\Controllers;

use App\Modules\Broadcasting\Application\ChannelAuthorizer;
use App\Modules\Broadcasting\Application\ChannelPattern;
use App\Modules\Broadcasting\Http\Requests\BroadcastAuthRequest;
use App\Modules\Broadcasting\Infrastructure\BroadcastSigner;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/broadcasting/auth — §3.1 steps 2-4.
 *
 * NOT the framework's `Broadcast::routes()`. That helper mounts
 * `/broadcasting/auth` on the `web` guard with session cookies; this platform
 * authenticates every client — including the Flutter app — with a Sanctum
 * bearer token under `/api/v1`, and the document specifies that path
 * explicitly. Mounting the framework's route as well would leave a second,
 * cookie-authenticated door onto the same private channels.
 *
 * THE RESPONSE DELIBERATELY SKIPS THE `data`/`meta` ENVELOPE. Every other
 * endpoint on this platform answers in the envelope of
 * docs/05-api/01-conventions.md §1.4, and this one must not: the body is
 * consumed by the Pusher/Echo client, not by application code, and that client
 * reads a top-level `auth` key. Wrapping it would produce a signature the
 * socket layer cannot find. ERRORS still use the standard envelope, because
 * those *are* read by application code and by the client's error handler.
 *
 * WHAT GETS SIGNED is the channel name as the client sent it, prefix included
 * (`private-org.184`). Reverb recomputes the HMAC over the same string; sign
 * the normalised name and every subscription fails with a mismatch that looks
 * like a key problem.
 */
final class BroadcastAuthController
{
    public function __construct(
        private readonly ChannelAuthorizer $authorizer,
        private readonly BroadcastSigner $signer,
    ) {}

    public function __invoke(BroadcastAuthRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            // Belt and braces: the route is behind auth:sanctum already.
            return ApiResponse::error('AUTH_TOKEN_INVALID', 'برای این عملیات باید وارد شوید.', 401);
        }

        $channelName = $request->channelName();
        $channel = ChannelPattern::parse($channelName);

        if ($channel === null) {
            return ApiResponse::error(
                'CHANNEL_UNKNOWN',
                'کانال درخواستی معتبر نیست.',
                422,
                ['channel' => $channelName],
            );
        }

        // A public channel needs no signature and never reaches this endpoint
        // from a correct client. Answering with one would imply the channel is
        // private, so it is refused with an explanation instead.
        if ($channel->isPublic) {
            return ApiResponse::error(
                'CHANNEL_PUBLIC',
                'این کانال عمومی است و به مجوز نیاز ندارد.',
                422,
                ['channel' => $channelName],
            );
        }

        $userId = (int) $user->getAuthIdentifier();

        if (! $this->authorizer->mayJoin($userId, $channelName)) {
            // 403 and nothing else. No hint about whether the organisation
            // exists — «private-org.999999» must look identical to a channel
            // belonging to a real member the caller is not part of, or this
            // endpoint becomes a membership oracle over the whole platform.
            return ApiResponse::error(
                'CHANNEL_FORBIDDEN',
                'شما مجاز به دریافت این کانال نیستید.',
                403,
            );
        }

        // Raw Pusher-protocol body. See the class docblock.
        return response()->json([
            'auth' => $this->signer->sign($request->socketId(), $channelName),
        ]);
    }
}
