<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Controllers;

use App\Modules\Web\Application\PanelBootstrap;
use App\Modules\Web\Application\PanelSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The panel's session endpoints — the bridge between Identity's API login and
 * the browser session that protects `/app/*`.
 *
 * `store` is the only unauthenticated route in the module. It takes an access
 * token the client has just obtained from `POST /api/v1/auth/login` and opens a
 * session for its owner; see PanelSessionService for why login itself is not
 * re-implemented here.
 *
 * `show` is the panel's re-hydration endpoint. The SPA calls it after a
 * reconnect (doc §1.6: "همگام‌سازی مجدد وضعیت پس از اتصال") to confirm the
 * session is still alive and to pick up a rotated token. Its payload is built
 * from the authenticated user alone, so it is the tenancy boundary of this
 * module and is tested as such.
 */
final class PanelSessionController extends Controller
{
    public function __construct(
        private readonly PanelSessionService $sessions,
        private readonly PanelBootstrap $bootstrap,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'error' => ['code' => 'AUTH_TOKEN_INVALID', 'message' => 'برای این عملیات باید وارد شوید.'],
            ], 401);
        }

        return response()->json([
            'data' => $this->bootstrap->forUser($user, $this->sessions->token($request->session())),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'access_token' => ['required', 'string', 'min:16', 'max:512'],
        ]);

        $user = $this->sessions->open($validated['access_token'], $request->session());

        if ($user === null) {
            // One message for unknown, expired and non-user tokens alike: which
            // of the three it was is information an attacker can use.
            //
            // Built here rather than thrown as a ValidationException, because
            // the global handler in bootstrap/app.php renders every one of
            // those as 422 — and a rejected credential is a 401, which is what
            // the client branches on to send the user back to sign in.
            return response()->json([
                'error' => [
                    'code' => 'AUTH_TOKEN_INVALID',
                    'message' => 'توکن نامعتبر یا منقضی است.',
                    'field_errors' => ['access_token' => ['توکن نامعتبر یا منقضی است.']],
                ],
            ], 401);
        }

        return response()->json([
            'data' => $this->bootstrap->forUser($user, $validated['access_token']),
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->sessions->close($request->session());

        return response()->json(null, 204);
    }
}
