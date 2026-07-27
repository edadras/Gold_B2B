<?php

declare(strict_types=1);

namespace App\Modules\Web;

use App\Modules\Shared\Concerns\ModuleServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * Trader web panel — docs/08-frontend-web/01-web-panels.md part A.
 *
 * Differs from every other module provider in one respect: it publishes browser
 * routes, not API routes. ModuleServiceProvider mounts `Http/routes.php` under
 * `/api/v1` with the `api` group; this module's routes live in `Http/web.php`
 * and are mounted under `/app` with the `web` group instead, so they get
 * sessions and CSRF and do not pollute the versioned API surface.
 *
 * The module holds no database tables of its own. Everything the panel shows is
 * fetched from `/api/v1` by the browser, which is why the dependency map in
 * tests/Architecture/ArchitectureTest.php lists only Shared and Identity for
 * `Web`: Identity for the viewer's identity and tenancy, Shared for nothing but
 * this base class.
 */
final class WebServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function boot(): void
    {
        parent::boot();

        // Views live in the application's own `resources/views/web/`, which is
        // already on the finder's path; `view('web.panel')` resolves without a
        // namespace registration.
        Route::prefix('app')
            ->middleware('web')
            ->group(__DIR__.'/Http/web.php');
    }
}
