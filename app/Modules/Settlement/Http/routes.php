<?php

declare(strict_types=1);

use App\Modules\Settlement\Http\Controllers\NettingBatchController;
use App\Modules\Settlement\Http\Controllers\SettlementController;
use Illuminate\Support\Facades\Route;

/*
 * Settlement routes — docs/05-api/02-endpoints.md §2.8 and §2.9.
 *
 * Middleware order on the signed endpoints is
 *   ['auth:sanctum', 'transaction.sign', 'idempotency']
 * and the order matters: a wrong TOTP code must be refused BEFORE the
 * idempotency key is recorded, or the corrected retry would replay the stored
 * 403 instead of confirming the payment.
 */

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    // Literal path first, so it is not swallowed by /settlements/{settlement}.
    Route::get('settlements/pending', [SettlementController::class, 'pending']);
    Route::get('settlements', [SettlementController::class, 'index']);
    Route::get('settlements/{settlement}', [SettlementController::class, 'show'])
        ->whereNumber('settlement');
    Route::get('settlements/{settlement}/events', [SettlementController::class, 'events'])
        ->whereNumber('settlement');

    Route::post('settlements/{settlement}/declare-payment', [SettlementController::class, 'declarePayment'])
        ->whereNumber('settlement')
        ->middleware('idempotency');

    Route::post('settlements/{settlement}/confirm-payment', [SettlementController::class, 'confirmPayment'])
        ->whereNumber('settlement')
        ->middleware(['transaction.sign', 'idempotency']);

    Route::post('settlements/{settlement}/confirm-delivery', [SettlementController::class, 'confirmDelivery'])
        ->whereNumber('settlement')
        ->middleware(['transaction.sign', 'idempotency']);

    Route::post('settlements/{settlement}/cancel', [SettlementController::class, 'cancel'])
        ->whereNumber('settlement')
        ->middleware('idempotency');

    Route::get('netting-batches', [NettingBatchController::class, 'index']);
    Route::get('netting-batches/{batch}', [NettingBatchController::class, 'show'])
        ->whereNumber('batch');
    Route::post('netting-batches/{batch}/accept', [NettingBatchController::class, 'accept'])
        ->whereNumber('batch')
        ->middleware(['transaction.sign', 'idempotency']);
    Route::post('netting-batches/{batch}/reject', [NettingBatchController::class, 'reject'])
        ->whereNumber('batch')
        ->middleware('idempotency');
});
