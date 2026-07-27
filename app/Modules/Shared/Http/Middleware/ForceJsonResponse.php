<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every /api/v1 route answers JSON, whatever the client asked for.
 *
 * Without this, a request that omits `Accept: application/json` — a curl by
 * hand, a webview, a misconfigured client — gets Laravel's HTML error page
 * instead of the documented error envelope, because every renderer in
 * bootstrap/app.php is guarded by `$request->expectsJson()`. An API that
 * sometimes returns HTML is an API the mobile client cannot parse.
 */
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
