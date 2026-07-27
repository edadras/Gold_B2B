<?php

declare(strict_types=1);

use App\Modules\Counterparty\Http\Controllers\CounterpartyController;
use App\Modules\Counterparty\Http\Controllers\MemberSearchController;
use Illuminate\Support\Facades\Route;

/*
 * Counterparty routes — docs/05-api/02-endpoints.md §2.11.
 *
 * Loaded by ModuleServiceProvider under prefix `api/v1` with the `api`
 * middleware group already applied, so neither is repeated here.
 *
 * `{counterparty}` is constrained to digits so `/counterparties/search` could
 * never be swallowed by the `{orgId}` route if one is added later, and so a
 * non-numeric id is a 404 from the router instead of a cast surprise in the
 * controller.
 *
 * §2.11 also lists `GET /members/{id}/reputation`. That route is owned by the
 * Reputation module and declared in its own routes file — Counterparty may not
 * depend on Reputation, and the reputation payload is Reputation's to define.
 */

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('counterparties', [CounterpartyController::class, 'index']);

    Route::get('counterparties/{counterparty}', [CounterpartyController::class, 'show'])
        ->whereNumber('counterparty');

    Route::get('counterparties/{counterparty}/statement', [CounterpartyController::class, 'statement'])
        ->whereNumber('counterparty');

    // 👑 — an owner-level permission, enforced in the controller.
    Route::put('counterparties/{counterparty}/limits', [CounterpartyController::class, 'updateLimits'])
        ->whereNumber('counterparty');

    Route::put('counterparties/{counterparty}/settings', [CounterpartyController::class, 'updateSettings'])
        ->whereNumber('counterparty');

    Route::post('counterparties/{counterparty}/confirm-balance', [CounterpartyController::class, 'confirmBalance'])
        ->whereNumber('counterparty');

    Route::get('members/search', [MemberSearchController::class, 'search']);
});
