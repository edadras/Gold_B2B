<?php

declare(strict_types=1);

use App\Modules\Broadcasting\Http\Controllers\BroadcastAuthController;
use Illuminate\Support\Facades\Route;

/*
 * Broadcasting routes — docs/05-api/03-realtime-webhooks.md §3.1.
 *
 * Loaded by ModuleServiceProvider under prefix `api/v1` with the `api`
 * middleware group already applied, so neither is repeated here.
 *
 * ONE ROUTE, AND IT IS A CREDENTIAL SURFACE: it converts a bearer token into a
 * signature that grants an uninterruptible stream, so it sits behind
 * `auth:sanctum`.
 *
 * `throttle:market-data`, not `throttle:auth`. The `auth` bucket is five
 * requests per fifteen minutes, sized for password attempts; a client
 * reconnecting after a dropped socket legitimately re-authorises every channel
 * it holds in one burst, and §3.4 has it doing that repeatedly under
 * exponential backoff. Five would lock out an honest reconnect within a
 * minute. `market-data` (300/min, keyed per organisation) absorbs the burst
 * while still being three orders of magnitude short of what enumerating
 * organisation ids would need — and the endpoint answers an identical 403 for
 * every id either way, so enumeration learns nothing regardless.
 */

Route::middleware(['auth:sanctum', 'throttle:market-data'])->group(function (): void {
    Route::post('broadcasting/auth', BroadcastAuthController::class);
});
