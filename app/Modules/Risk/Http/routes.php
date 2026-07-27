<?php

declare(strict_types=1);

use App\Modules\Risk\Http\Controllers\LimitIncreaseController;
use App\Modules\Risk\Http\Controllers\RiskController;
use App\Modules\Risk\Http\Controllers\UserLimitController;
use Illuminate\Support\Facades\Route;

/*
 * Risk routes — docs/05-api/02-endpoints.md §2.15, plus the one §2.2 route that
 * writes a Risk table.
 *
 * Loaded by ModuleServiceProvider under prefix `api/v1` with the `api`
 * middleware group already applied, so neither is repeated here.
 *
 * Everything is behind a token and on the general `api` budget: these are
 * low-frequency dashboard reads, not a trading hot path, so they have no reason
 * to compete with `throttle:orders` for a separate allowance.
 */

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('risk/profile', [RiskController::class, 'profile']);
    Route::get('risk/limits', [RiskController::class, 'limits']);
    Route::get('risk/exposure', [RiskController::class, 'exposure']);
    Route::get('risk/collaterals', [RiskController::class, 'collaterals']);

    // 👑 — an owner-level permission, enforced in the controller.
    Route::post('risk/limit-increase-request', [LimitIncreaseController::class, 'store']);

    /*
     * §2.2 by path, Risk by ownership: `user_limits` is a Risk table and the
     * ceiling it holds is enforced by RiskGuard, so the endpoint is declared
     * here rather than in Identity's routes. The sibling `/organization/users`
     * routes stay with Identity; Laravel merges the two groups by path.
     */
    Route::put('organization/users/{user}/limits', [UserLimitController::class, 'update'])
        ->whereNumber('user');
});
