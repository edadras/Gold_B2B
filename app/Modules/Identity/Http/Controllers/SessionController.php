<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\AuthService;
use App\Modules\Identity\Http\Resources\SessionResource;
use App\Modules\Identity\Infrastructure\Models\UserSession;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `/auth/sessions` — the "where am I signed in?" screen. */
final class SessionController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly AuthService $auth,
    ) {
        parent::__construct($authorization);
    }

    public function index(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        $currentId = $this->currentSessionId($request);

        $sessions = UserSession::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get();

        return ApiResponse::collection(
            $sessions->map(static fn (UserSession $s): SessionResource => new SessionResource($s, $currentId))->all(),
        );
    }

    /**
     * Sessions are scoped to the caller, never to the organisation: an OWNER
     * may not revoke a colleague's session from here. A missing id and somebody
     * else's id are both 404, so the endpoint cannot be used to enumerate
     * session ids.
     */
    public function destroy(Request $request, int $sessionId): JsonResponse
    {
        /** @var UserSession|null $session */
        $session = UserSession::query()->find($sessionId);

        if ($session === null || (int) $session->user_id !== $this->userId($request)) {
            throw $this->notFound();
        }

        $this->auth->logout($session, 'revoked_by_user');

        return ApiResponse::noContent();
    }

    private function currentSessionId(Request $request): ?int
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return null;
        }

        $id = UserSession::query()
            ->where('user_id', $request->user()?->getAuthIdentifier())
            ->where('token_hash', hash('sha256', $token))
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
