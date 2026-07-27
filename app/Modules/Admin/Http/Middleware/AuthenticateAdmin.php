<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session authentication for the operator panel.
 *
 * Separate from the API's `auth:sanctum` on purpose: the panel is a
 * server-rendered, cookie-session application and a bearer token stolen from a
 * member's phone must never open an admin page.
 */
final class AuthenticateAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->guest(route('admin.login'));
        }

        return $next($request);
    }
}
