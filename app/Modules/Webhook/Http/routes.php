<?php

declare(strict_types=1);

use App\Modules\Webhook\Http\Controllers\WebhookController;
use App\Modules\Webhook\Http\Controllers\WebhookDeliveryController;
use Illuminate\Support\Facades\Route;

/*
 * Webhook management — docs/05-api/03-realtime-webhooks.md §3.13, all eight rows.
 *
 * Loaded by ModuleServiceProvider under prefix `api/v1` with the `api`
 * middleware group already applied, so neither is repeated here.
 *
 * These are the MANAGEMENT endpoints. Nothing in this file receives a webhook;
 * the platform is the sender. There is deliberately no inbound endpoint and no
 * route that takes a URL and fetches it — the only outbound requests this
 * subsystem makes come from a stored, guarded registration.
 *
 * `auth:sanctum` on every route, and every controller action re-asserts both
 * authorisation legs plus the tenant filter. A webhook is a credential: whoever
 * owns the URL receives the organisation's trade flow.
 *
 * Rate limiter: the general `api` budget. Registration and rotation are rare,
 * the list and history views are cheap reads, and the test ping is bounded by
 * the queue rather than by the request.
 *
 * `{webhook}` and `{delivery}` are whereNumber-constrained so the literal
 * segments (`rotate-secret`, `test`, `deliveries`) can never be swallowed by the
 * parameter, and so a non-numeric id is a 404 from the router rather than a
 * cast to 0 inside a controller.
 */

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('webhooks', [WebhookController::class, 'index']);
    Route::post('webhooks', [WebhookController::class, 'store']);

    Route::put('webhooks/{webhook}', [WebhookController::class, 'update'])
        ->whereNumber('webhook');
    Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy'])
        ->whereNumber('webhook');

    Route::post('webhooks/{webhook}/rotate-secret', [WebhookController::class, 'rotateSecret'])
        ->whereNumber('webhook');
    Route::post('webhooks/{webhook}/test', [WebhookController::class, 'test'])
        ->whereNumber('webhook');
    Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries'])
        ->whereNumber('webhook');

    Route::post('webhook-deliveries/{delivery}/retry', [WebhookDeliveryController::class, 'retry'])
        ->whereNumber('delivery');
});
