<?php

declare(strict_types=1);

use App\Modules\Dispute\Http\Controllers\DisputeController;
use App\Modules\Dispute\Http\Controllers\DisputeEvidenceController;
use Illuminate\Support\Facades\Route;

/*
 * Dispute routes — docs/05-api/02-endpoints.md §2.12.
 *
 * Opening a dispute and accepting a claim both move money: opening locks the
 * disputed amount, accepting concedes it. Both therefore carry an idempotency
 * key, and accepting additionally requires a re-signed transaction, since it is
 * an irreversible concession made against one's own balance.
 *
 * Replying, adding evidence and escalating change no balance, so they are
 * ordinary authenticated calls.
 */

Route::middleware(['auth:sanctum', 'organization.active', 'throttle:api'])
    ->group(function (): void {
        Route::get('disputes', [DisputeController::class, 'index']);

        Route::post('disputes', [DisputeController::class, 'store'])
            ->middleware('idempotency');

        Route::get('disputes/{dispute}', [DisputeController::class, 'show'])
            ->whereNumber('dispute');

        Route::post('disputes/{dispute}/reply', [DisputeController::class, 'reply'])
            ->whereNumber('dispute');

        // Conceding the claim releases the hold in the claimant's favour, so it
        // is signed as well as idempotent.
        Route::post('disputes/{dispute}/accept', [DisputeController::class, 'accept'])
            ->whereNumber('dispute')
            ->middleware(['transaction.sign', 'idempotency']);

        Route::post('disputes/{dispute}/escalate', [DisputeController::class, 'escalate'])
            ->whereNumber('dispute');

        Route::post('disputes/{dispute}/withdraw', [DisputeController::class, 'withdraw'])
            ->whereNumber('dispute')
            ->middleware('idempotency');

        Route::get('disputes/{dispute}/evidences', [DisputeEvidenceController::class, 'index'])
            ->whereNumber('dispute');

        Route::post('disputes/{dispute}/evidences', [DisputeEvidenceController::class, 'store'])
            ->whereNumber('dispute')
            ->middleware('throttle:uploads');
    });
