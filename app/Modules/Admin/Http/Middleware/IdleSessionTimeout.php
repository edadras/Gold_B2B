<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * §1.11: «جلسه کوتاه (۳۰ دقیقه بی‌کاری ► خروج)».
 *
 * Enforced per request rather than by the session cookie's lifetime, because
 * the cookie lifetime is an absolute expiry and what the rule asks for is
 * *idleness*: an unattended terminal must log itself out even if the operator
 * was active five minutes before walking away.
 */
final class IdleSessionTimeout
{
    public const IDLE_SECONDS = 1800;

    private const KEY = 'admin.last_activity_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check() || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $lastActivity = $session->get(self::KEY);

        if (is_int($lastActivity) && (time() - $lastActivity) > self::IDLE_SECONDS) {
            Auth::logout();
            $session->invalidate();
            $session->regenerateToken();

            return redirect()
                ->route('admin.login')
                ->with('status', 'به دلیل بی‌کاری بیش از ۳۰ دقیقه از سامانه خارج شدید.');
        }

        $session->put(self::KEY, time());

        return $next($request);
    }
}
