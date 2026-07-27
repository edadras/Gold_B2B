<?php

declare(strict_types=1);

use App\Modules\Pricing\Http\Controllers\MarketDataController;
use App\Modules\Pricing\Http\Controllers\PriceAlertController;
use Illuminate\Support\Facades\Route;

/*
 * Pricing routes — docs/05-api/02-endpoints.md §2.4.
 *
 * The `/market/*` split between this module and Trading follows ownership, not
 * URL shape: quotes, candles and the reference price are Pricing's tables, while
 * depth, the tape and the session belong to the order book in Trading. The
 * client sees one coherent /market namespace either way.
 *
 * `market-data` limiter: 300/minute per organisation, because a live price
 * widget polls far harder than a form does.
 */

Route::middleware(['auth:sanctum', 'throttle:market-data'])->group(function (): void {
    Route::get('market/quotes', [MarketDataController::class, 'quotes']);
    // Registered before the {code} route so the literal path wins.
    Route::get('market/reference-price', [MarketDataController::class, 'referencePrice']);
    Route::get('market/quotes/{code}', [MarketDataController::class, 'quote']);
    Route::get('market/candles/{code}', [MarketDataController::class, 'candles']);
});

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('price-alerts', [PriceAlertController::class, 'index']);
    Route::post('price-alerts', [PriceAlertController::class, 'store']);
    Route::delete('price-alerts/{alert}', [PriceAlertController::class, 'destroy'])
        ->whereNumber('alert');
});
