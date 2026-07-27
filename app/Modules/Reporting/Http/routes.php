<?php

declare(strict_types=1);

use App\Modules\Reporting\Http\Controllers\ReportController;
use App\Modules\Reporting\Http\Controllers\ReportExportController;
use Illuminate\Support\Facades\Route;

/*
 * Reporting routes — docs/05-api/02-endpoints.md §2.13 (the /reports half).
 *
 * Loaded by ModuleServiceProvider under prefix `api/v1` with the `api`
 * middleware group already applied.
 *
 * `GET /reports/trial-balance` is NOT in this file. Reporting may depend on
 * Shared and Identity only, and the trial balance is built by Accounting's
 * TrialBalanceService, so that route is declared in Accounting's routes.php
 * despite sharing the /reports prefix.
 *
 * Rate limiter: `throttle:reports` — 20 per hour, keyed on the organisation.
 * A report is an aggregate scan over a member's whole history and the limit is
 * deliberately far below the general `api` budget.
 */

Route::middleware(['auth:sanctum', 'throttle:reports'])->group(function (): void {
    Route::get('reports/gold-flow', [ReportController::class, 'goldFlow']);
    Route::get('reports/rial-flow', [ReportController::class, 'rialFlow']);
    Route::get('reports/trades', [ReportController::class, 'trades']);
    Route::get('reports/pnl', [ReportController::class, 'pnl']);
    Route::get('reports/daily-profit', [ReportController::class, 'dailyProfit']);
    Route::get('reports/inventory', [ReportController::class, 'inventory']);
    Route::get('reports/fees', [ReportController::class, 'fees']);

    Route::post('reports/export', [ReportExportController::class, 'store']);
    Route::get('reports/exports', [ReportExportController::class, 'index']);
    Route::get('reports/exports/{job}', [ReportExportController::class, 'show'])
        ->whereNumber('job');
});

/*
 * The download link, and the one route on the reporting surface that carries no
 * bearer token.
 *
 * ReportJobService::signedUrl() hardcodes `/api/v1/reports/download/{token}`,
 * so this path is part of the contract and cannot move. The 64-hex token is
 * itself the credential — see ReportExportController::download() for the full
 * reasoning and the tradeoff it accepts.
 *
 * `throttle:api` rather than `throttle:reports`: with no authenticated user the
 * limiter keys on the client IP, and the reports bucket (20/hour) would stop a
 * member from downloading their own morning's exports over an office NAT. The
 * token space is 2^256, so rate limiting is not what stands between an attacker
 * and a file here.
 */
Route::middleware('throttle:api')->group(function (): void {
    Route::get('reports/download/{token}', [ReportExportController::class, 'download'])
        ->where('token', '[0-9a-f]{64}');
});
