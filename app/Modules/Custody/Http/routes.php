<?php

declare(strict_types=1);

use App\Modules\Custody\Http\Controllers\LotController;
use App\Modules\Custody\Http\Controllers\LotOperationController;
use App\Modules\Custody\Http\Controllers\PublicVerificationController;
use App\Modules\Custody\Http\Controllers\VaultController;
use App\Modules\Custody\Http\Controllers\VaultDepositController;
use App\Modules\Custody\Http\Controllers\VaultWithdrawalController;
use Illuminate\Support\Facades\Route;

/*
 * Custody routes — docs/05-api/02-endpoints.md §2.10.
 *
 * Loaded by ModuleServiceProvider under prefix `api/v1` with the `api`
 * middleware group already applied; nothing here repeats either.
 *
 * MIDDLEWARE ORDER. Where a route carries both markers the list is
 * ['transaction.sign', 'idempotency'], in that order, on top of the group's
 * ['auth:sanctum', 'throttle:api']. The signature must be verified BEFORE the
 * idempotency key is recorded: a refused code would otherwise burn the key, and
 * the retry with a correct code would replay the stored 403 forever.
 *
 * 🔑 = idempotency (§1.10), ✍️ = transaction.sign.
 */

/*
 * PUBLIC — no auth:sanctum. Anyone holding the piece may scan it.
 * `throttle:api` keys on IP for an anonymous caller, which is what caps a
 * scraper walking the token space (a 256-bit space, so walking it is
 * hopeless anyway — the limit is there for the database's sake).
 */
Route::middleware('throttle:api')->group(function (): void {
    Route::get('verify/{qr_token}', [PublicVerificationController::class, 'show'])
        ->where('qr_token', '[A-Za-z0-9]+');
});

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('lots', [LotController::class, 'index']);

    // Registered before the {lot} routes so "merge" is never read as a lot id.
    // 🔑 ✍️
    Route::post('lots/merge', [LotOperationController::class, 'merge'])
        ->middleware(['transaction.sign', 'idempotency']);

    Route::get('lots/{lot}', [LotController::class, 'show'])->whereNumber('lot');
    Route::get('lots/{lot}/lineage', [LotController::class, 'lineage'])->whereNumber('lot');
    Route::get('lots/{lot}/assays', [LotController::class, 'assays'])->whereNumber('lot');

    // 🔑 ✍️
    Route::post('lots/{lot}/split', [LotOperationController::class, 'split'])
        ->middleware(['transaction.sign', 'idempotency'])
        ->whereNumber('lot');

    // 🔑 — no signature: this does not move value out of the member's control,
    // it only parks the lot with a laboratory.
    Route::post('lots/{lot}/send-to-assay', [LotOperationController::class, 'sendToAssay'])
        ->middleware('idempotency')
        ->whereNumber('lot');

    Route::get('vault/deposits', [VaultDepositController::class, 'index']);
    // 🔑
    Route::post('vault/deposits', [VaultDepositController::class, 'store'])
        ->middleware('idempotency');

    Route::get('vault/withdrawals', [VaultWithdrawalController::class, 'index']);
    // 🔑 ✍️
    Route::post('vault/withdrawals', [VaultWithdrawalController::class, 'store'])
        ->middleware(['transaction.sign', 'idempotency']);

    // 👑 dual control — the second, different user signs off.
    Route::post('vault/withdrawals/{operation}/approve', [VaultWithdrawalController::class, 'approve'])
        ->whereNumber('operation');

    // Platform reference data, not tenant data — see VaultController::index.
    Route::get('vaults', [VaultController::class, 'index']);
});
