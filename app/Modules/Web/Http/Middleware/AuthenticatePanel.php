<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;

/**
 * Session auth for `/app/*`, redirecting to the panel's own sign-in screen.
 *
 * The framework's `auth` alias redirects to a route named `login`, which this
 * application does not define — and defining one would claim a global name the
 * admin panel may want. Subclassing is cheaper than negotiating over it.
 *
 * XHR and JSON callers get a 401 instead of a redirect, because the SPA needs
 * to distinguish "your session expired" from "here is a login page".
 */
final class AuthenticatePanel extends Authenticate
{
    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() ? null : url('/app/login');
    }
}
