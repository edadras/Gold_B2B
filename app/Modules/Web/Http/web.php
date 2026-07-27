<?php

declare(strict_types=1);

use App\Modules\Web\Http\Controllers\PanelController;
use App\Modules\Web\Http\Controllers\PanelSessionController;
use App\Modules\Web\Http\Middleware\AuthenticatePanel;
use Illuminate\Support\Facades\Route;

/*
 * Trader web panel — docs/08-frontend-web/01-web-panels.md part A.
 *
 * Mounted by WebServiceProvider under the `/app` prefix with the `web`
 * middleware group (session + CSRF). Deliberately NOT `Http/routes.php`, which
 * ModuleServiceProvider would publish under `/api/v1`: none of this is API
 * surface, it is a browser front end that consumes the API.
 *
 * Route names are prefixed `web.` so they cannot collide with the admin panel.
 */

Route::get('login', [PanelController::class, 'login'])
    ->middleware('guest')
    ->name('web.login');

// The token-for-session exchange. Unauthenticated by necessity — it is what
// creates the session — and rate limited because it accepts a secret.
Route::post('session', [PanelSessionController::class, 'store'])
    ->middleware('throttle:auth')
    ->name('web.session.store');

Route::middleware(AuthenticatePanel::class)->group(function (): void {
    Route::get('session', [PanelSessionController::class, 'show'])->name('web.session.show');
    Route::delete('session', [PanelSessionController::class, 'destroy'])->name('web.session.destroy');

    Route::get('/', [PanelController::class, 'show'])->name('web.panel');

    // One shell per screen so a refresh or a bookmark lands where the trader
    // left off. `{screen}` is validated against PanelScreen, and anything else
    // falls through to a 404 rather than rendering the default screen under a
    // URL that does not exist.
    Route::get('{screen}', [PanelController::class, 'show'])
        ->where('screen', 'terminal|ledger|settlements|orders|trades|lots|counterparties|reports')
        ->name('web.panel.screen');
});
