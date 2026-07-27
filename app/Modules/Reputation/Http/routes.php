<?php

declare(strict_types=1);

use App\Modules\Reputation\Http\Controllers\ReputationController;
use Illuminate\Support\Facades\Route;

/*
 * Reputation routes.
 *
 * Loaded by ModuleServiceProvider under prefix `api/v1` with the `api`
 * middleware group already applied, so neither is repeated here.
 *
 * `GET /members/{id}/reputation` is listed under §2.11 (طرف‌حساب) in
 * docs/05-api/02-endpoints.md, because that is where a member meets it. It is
 * declared here rather than in Counterparty's routes because the payload is
 * Reputation's to define and Counterparty may not depend on this module —
 * Laravel merges the two `/members/*` groups by path.
 *
 * `GET /reputation/me` is not in the endpoint table; it is the member's own
 * side of §14.9's "a member must see exactly its own statistics".
 */

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('members/{member}/reputation', [ReputationController::class, 'show'])
        ->whereNumber('member');

    Route::get('reputation/me', [ReputationController::class, 'me']);
});
