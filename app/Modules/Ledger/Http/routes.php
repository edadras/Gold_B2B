<?php

declare(strict_types=1);

use App\Modules\Ledger\Http\Controllers\BalanceController;
use App\Modules\Ledger\Http\Controllers\LedgerEntryController;
use Illuminate\Support\Facades\Route;

/*
 * Ledger routes — docs/05-api/02-endpoints.md §2.3.
 *
 * All reads, all tenant-scoped, all on the general `api` budget. The balances
 * screen is polled by every open client, so it is deliberately not on the
 * tighter `reports` limiter.
 */

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('balances', [BalanceController::class, 'index']);
    Route::get('balances/gold', [BalanceController::class, 'gold']);
    Route::get('balances/rial', [BalanceController::class, 'rial']);

    Route::get('ledger/gold', [LedgerEntryController::class, 'gold']);
    Route::get('ledger/rial', [LedgerEntryController::class, 'rial']);
    Route::get('ledger/entries/{entry}', [LedgerEntryController::class, 'show'])
        ->whereNumber('entry');
});
