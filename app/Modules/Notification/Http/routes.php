<?php

declare(strict_types=1);

use App\Modules\Notification\Http\Controllers\DeviceController;
use App\Modules\Notification\Http\Controllers\NotificationController;
use App\Modules\Notification\Http\Controllers\NotificationPreferenceController;
use Illuminate\Support\Facades\Route;

/*
 * Notification routes — docs/05-api/02-endpoints.md §2.14.
 *
 * Loaded by ModuleServiceProvider under prefix `api/v1` with the `api`
 * middleware group already applied, so neither is repeated here.
 *
 * EVERY ROUTE IN THIS FILE IS USER-SCOPED, NOT ORGANIZATION-SCOPED. The feed,
 * the unread badge, the preference rows and the push tokens all belong to one
 * person; two colleagues in the same organisation see different data and
 * neither can reach the other's. The controllers say so at each authorisation
 * site and key every query on the caller's own user id.
 *
 * Rate limiter: the general `api` budget. These are chatty, cheap reads that
 * the mobile client polls, so they do not warrant the `reports` bucket, and
 * they are not a credential surface so they do not warrant `auth`.
 */

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
    // Declared after `read-all` so the literal segment is never swallowed by
    // the parameter; whereNumber makes that structural rather than positional.
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->whereNumber('notification');

    Route::get('notifications/preferences', [NotificationPreferenceController::class, 'index']);
    Route::put('notifications/preferences', [NotificationPreferenceController::class, 'update']);

    Route::get('devices', [DeviceController::class, 'index']);
    Route::post('devices', [DeviceController::class, 'store']);
    // The token is the identifier the client already holds; it is matched
    // against the caller's own rows only, and an unknown or foreign token is
    // answered identically to a revoked one.
    Route::delete('devices/{token}', [DeviceController::class, 'destroy'])
        ->where('token', '[A-Za-z0-9._:%\-]+');
});
