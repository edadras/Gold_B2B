<?php

declare(strict_types=1);

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\OpenApiController;
use Illuminate\Support\Facades\Route;

/*
 * Platform-level API routes — docs/05-api/02-endpoints.md §2.17.
 *
 * Module routes are loaded by ModuleServiceProvider from
 * app/Modules/<Name>/Http/routes.php. These few are not owned by any module:
 * /meta/enums aggregates across all of them, and no module is allowed to do
 * that (see the dependency graph in tests/Architecture/ArchitectureTest.php).
 *
 * Registered by App\Providers\AppServiceProvider so bootstrap/providers.php
 * stays untouched.
 */

Route::prefix('api/v1')->middleware('api')->group(function (): void {
    // Public: an uptime probe cannot hold a token.
    Route::get('health', [HealthController::class, 'shallow']);
    Route::get('openapi.json', OpenApiController::class);

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::get('health/deep', [HealthController::class, 'deep']);
        Route::get('meta/enums', [MetaController::class, 'enums']);
        Route::get('meta/settings', [MetaController::class, 'settings']);
        Route::get('meta/calendar', [MetaController::class, 'calendar']);
    });
});
