<?php

declare(strict_types=1);

namespace App\Modules\Web\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Exchanges an API access token for a browser session, and back again.
 *
 * WHY THE PANEL DOES NOT RE-IMPLEMENT LOGIN
 * -----------------------------------------
 * Identity already owns authentication: lockout counters, the TOTP second
 * factor, session rows, refresh-token rotation. Writing a second login path in
 * the Web module would duplicate all of it and — worse — would duplicate it
 * imperfectly, which on a credential surface is how accounts get taken over.
 *
 * So the panel's sign-in screen posts to `POST /api/v1/auth/login` exactly like
 * the Flutter client does, and hands the resulting access token here. This
 * service verifies the token through Sanctum, then opens a *server* session for
 * the same user. That gives two things at once:
 *
 *   · `/app/*` routes are protected by ordinary session auth, so an
 *     unauthenticated browser is redirected instead of downloading a shell it
 *     cannot populate; and
 *   · the SPA keeps a bearer token for `/api/v1`, which is stateless and does
 *     not participate in the session guard.
 *
 * The token is held in the session rather than in `localStorage`: a session
 * cookie is HttpOnly and dies with the browser, whereas anything in web storage
 * is readable by any script that manages to run on the page.
 */
final class PanelSessionService
{
    /** Session key holding the bearer token handed back to the SPA. */
    public const TOKEN_KEY = 'panel.api_token';

    /**
     * Verify a plaintext Sanctum token and open a session for its owner.
     *
     * Returns null when the token is unknown, expired, or belongs to something
     * that is not an authenticatable user. Callers must translate that into a
     * 401 without saying which of the three it was.
     */
    public function open(string $plainTextToken, Session $session): ?Authenticatable
    {
        $token = PersonalAccessToken::findToken($plainTextToken);

        if ($token === null) {
            return null;
        }

        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            return null;
        }

        $owner = $token->tokenable;

        if (! $owner instanceof Authenticatable) {
            return null;
        }

        // Fixation guard: a brand-new session id for a brand-new identity.
        $session->regenerate();
        Auth::guard('web')->login($owner);
        $session->put(self::TOKEN_KEY, $plainTextToken);

        return $owner;
    }

    /** The bearer token belonging to the current browser session, if any. */
    public function token(Session $session): ?string
    {
        $token = $session->get(self::TOKEN_KEY);

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Close the browser session.
     *
     * The API token itself is deliberately NOT deleted here. `POST
     * /api/v1/auth/logout` owns that, it is what updates Identity's
     * `user_sessions` row, and the panel calls it before this. Revoking the
     * token from two places would leave Identity's session bookkeeping stale.
     */
    public function close(Session $session): void
    {
        Auth::guard('web')->logout();
        $session->forget(self::TOKEN_KEY);
        $session->invalidate();
        $session->regenerateToken();
    }
}
