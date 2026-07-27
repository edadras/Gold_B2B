<?php

declare(strict_types=1);

use App\Modules\Kyc\Http\Controllers\BankAccountController;
use App\Modules\Kyc\Http\Controllers\DocumentController;
use App\Modules\Kyc\Http\Controllers\KycController;
use App\Modules\Kyc\Http\Controllers\LicenseController;
use Illuminate\Support\Facades\Route;

/*
 * KYC routes — docs/05-api/02-endpoints.md §2.2.
 *
 * Loaded by ModuleServiceProvider under prefix `api/v1` with the `api`
 * middleware group already applied; nothing here repeats either.
 *
 * Rate limiters: everything uses the general `api` budget except the upload,
 * which uses `uploads` (50/hour/organisation) — an upload costs storage and a
 * malware scan, so it is metered per hour rather than per minute.
 *
 * ✍️ `transaction.sign` on the bank-account writes: those rows are where money
 * is sent, so they are re-authenticated with a TOTP code rather than trusted to
 * a bearer token that may have been lifted from a device. Where a route ever
 * gains `idempotency` too, the signature stays first in the list, so a refused
 * code cannot burn the key and make the corrected retry replay the 403.
 */

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('organization/kyc', [KycController::class, 'show']);
    Route::post('organization/kyc/submit', [KycController::class, 'submit']);

    Route::get('organization/documents', [DocumentController::class, 'index']);
    Route::delete('organization/documents/{document}', [DocumentController::class, 'destroy'])
        ->whereNumber('document');

    Route::get('organization/licenses', [LicenseController::class, 'index']);
    Route::post('organization/licenses', [LicenseController::class, 'store']);

    Route::get('organization/bank-accounts', [BankAccountController::class, 'index']);

    // 👑 ✍️ — see the note above on ordering.
    Route::post('organization/bank-accounts', [BankAccountController::class, 'store'])
        ->middleware('transaction.sign');
    Route::delete('organization/bank-accounts/{bank_account}', [BankAccountController::class, 'destroy'])
        ->middleware('transaction.sign')
        ->whereNumber('bank_account');
});

Route::middleware(['auth:sanctum', 'throttle:uploads'])->group(function (): void {
    Route::post('organization/documents', [DocumentController::class, 'store']);
});
