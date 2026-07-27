<?php

declare(strict_types=1);

use App\Modules\Trading\Http\Controllers\MarketController;
use App\Modules\Trading\Http\Controllers\OrderController;
use App\Modules\Trading\Http\Controllers\OtcOfferController;
use App\Modules\Trading\Http\Controllers\RfqController;
use Illuminate\Support\Facades\Route;

/*
 * Trading routes — docs/05-api/02-endpoints.md §2.4 (instruments + book),
 * §2.5 (orders), §2.6 (OTC) and §2.7 (RFQ).
 *
 * Limiters: reads of shared market data get `market-data` (300/min) because a
 * live depth widget polls hard; order entry gets `orders` (60/min) keyed on the
 * organisation, so several traders in one member share one budget.
 *
 * Middleware order on mutating routes is ['auth:sanctum', 'idempotency'] — and
 * where a signature is also required, `transaction.sign` goes BEFORE
 * `idempotency` so a refused signature does not burn the key.
 */

Route::middleware(['auth:sanctum', 'throttle:market-data'])->group(function (): void {
    Route::get('instruments', [MarketController::class, 'instruments']);
    Route::get('instruments/{code}', [MarketController::class, 'instrument']);

    Route::get('market/depth/{code}', [MarketController::class, 'depth']);
    Route::get('market/trades/{code}', [MarketController::class, 'trades']);
    Route::get('market/sessions/{code}', [MarketController::class, 'session']);
});

Route::middleware(['auth:sanctum', 'throttle:orders'])->group(function (): void {
    Route::get('orders', [OrderController::class, 'index']);
    // Registered before `orders/{order}` so the literal wins the match.
    Route::post('orders/cancel-all', [OrderController::class, 'cancelAll'])
        ->middleware('idempotency');
    Route::post('orders', [OrderController::class, 'store'])
        ->middleware('idempotency');
    Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
    Route::get('orders/{order}/fills', [OrderController::class, 'fills'])->whereNumber('order');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])
        ->whereNumber('order')
        ->middleware('idempotency');
});

Route::middleware(['auth:sanctum', 'throttle:orders'])->group(function (): void {
    Route::get('otc-offers', [OtcOfferController::class, 'index']);
    Route::post('otc-offers', [OtcOfferController::class, 'store'])->middleware('idempotency');
    Route::get('otc-offers/{offer}', [OtcOfferController::class, 'show'])->whereNumber('offer');
    Route::post('otc-offers/{offer}/accept', [OtcOfferController::class, 'accept'])
        ->whereNumber('offer')->middleware('idempotency');
    Route::post('otc-offers/{offer}/counter', [OtcOfferController::class, 'counter'])
        ->whereNumber('offer')->middleware('idempotency');
    // Reject and cancel are not marked 🔑 in the catalogue: both are idempotent
    // by nature — the second call finds a closed offer and refuses it.
    Route::post('otc-offers/{offer}/reject', [OtcOfferController::class, 'reject'])->whereNumber('offer');
    Route::post('otc-offers/{offer}/cancel', [OtcOfferController::class, 'cancel'])->whereNumber('offer');
});

Route::middleware(['auth:sanctum', 'throttle:orders'])->group(function (): void {
    Route::get('rfqs', [RfqController::class, 'index']);
    Route::get('rfqs/inbox', [RfqController::class, 'inbox']);
    Route::post('rfqs', [RfqController::class, 'store'])->middleware('idempotency');
    Route::get('rfqs/{rfq}', [RfqController::class, 'show'])->whereNumber('rfq');
    Route::post('rfqs/{rfq}/cancel', [RfqController::class, 'cancel'])->whereNumber('rfq');
    Route::get('rfqs/{rfq}/quotes', [RfqController::class, 'quotes'])->whereNumber('rfq');
    Route::post('rfqs/{rfq}/quotes', [RfqController::class, 'quote'])
        ->whereNumber('rfq')->middleware('idempotency');

    Route::post('rfq-quotes/{quote}/accept', [RfqController::class, 'acceptQuote'])
        ->whereNumber('quote')->middleware('idempotency');
    Route::post('rfq-quotes/{quote}/withdraw', [RfqController::class, 'withdrawQuote'])->whereNumber('quote');
});
