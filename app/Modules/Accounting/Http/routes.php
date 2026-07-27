<?php

declare(strict_types=1);

use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Accounting\Http\Controllers\TrialBalanceController;
use App\Modules\Accounting\Http\Controllers\UnrealizedPnlController;
use Illuminate\Support\Facades\Route;

/*
 * Accounting routes — docs/05-api/02-endpoints.md §2.13 (the accounting half).
 *
 * Loaded by ModuleServiceProvider under prefix `api/v1` with the `api`
 * middleware group already applied.
 *
 * WHY `GET /reports/trial-balance` IS DECLARED IN THIS FILE.
 *
 * The documentation lists the trial balance among the reports, so the path
 * keeps the /reports prefix the client already knows. The implementation cannot
 * live in Reporting: TrialBalanceService is Accounting's, it reads Accounting's
 * `journal_entries` and `journal_lines`, and Reporting is permitted to depend on
 * Shared and Identity only (tests/Architecture/ArchitectureTest.php). Routes are
 * global — nothing requires a module's routes to share its module's prefix — so
 * the owner of the code declares the route and the URL stays where §2.13 put
 * it. See TrialBalanceController for the alternatives that were rejected.
 *
 * `GET /reports/pnl` is Reporting's and is NOT duplicated here. The unrealised
 * half, which §9.4 keeps under «اطلاعاتی (غیر از دفتر)» and which must never be
 * added into the accounting result, is exposed separately as
 * `GET /accounting/unrealized-pnl`.
 *
 * Rate limiter: `throttle:reports` — these are aggregate scans over a member's
 * whole journal, priced like the other reports rather than like a chatty read.
 */

Route::middleware(['auth:sanctum', 'throttle:reports'])->group(function (): void {
    Route::get('accounting/journal', [JournalController::class, 'index']);
    Route::get('accounting/export', [JournalController::class, 'export']);
    Route::get('accounting/unrealized-pnl', [UnrealizedPnlController::class, 'show']);

    Route::get('reports/trial-balance', [TrialBalanceController::class, 'show']);
});
