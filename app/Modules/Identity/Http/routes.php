<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\OrganizationController;
use App\Modules\Identity\Http\Controllers\OrganizationUserController;
use App\Modules\Identity\Http\Controllers\RepresentativeController;
use App\Modules\Identity\Http\Controllers\SessionController;
use App\Modules\Identity\Http\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
 * Identity routes — docs/05-api/02-endpoints.md §2.1 and §2.2.
 *
 * Loaded by ModuleServiceProvider under prefix `api/v1` with the `api`
 * middleware group already applied.
 *
 * Rate limiters: the unauthenticated credential surface uses `auth` (5 per 15
 * minutes, keyed on mobile+IP) because it is the credential-stuffing target.
 * Everything behind a token uses the general `api` budget.
 */

Route::middleware('throttle:auth')->group(function (): void {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/login/2fa', [AuthController::class, 'loginTwoFactor']);
    Route::post('auth/refresh', [AuthController::class, 'refresh']);
    Route::post('auth/otp/send', [AuthController::class, 'sendOtp']);
    Route::post('auth/otp/verify', [AuthController::class, 'verifyOtp']);
    Route::post('auth/password/forgot', [AuthController::class, 'forgotPassword']);
    Route::post('auth/password/reset', [AuthController::class, 'resetPassword']);
});

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::put('auth/password', [AuthController::class, 'changePassword']);

    Route::get('auth/sessions', [SessionController::class, 'index']);
    Route::delete('auth/sessions/{session}', [SessionController::class, 'destroy'])
        ->whereNumber('session');

    Route::post('auth/2fa/enable', [TwoFactorController::class, 'enable']);
    Route::post('auth/2fa/confirm', [TwoFactorController::class, 'confirm']);
    // ✍️ Turning the second factor off is exactly the action an attacker with
    // a stolen token would perform first, so it must itself be signed.
    Route::delete('auth/2fa', [TwoFactorController::class, 'disable'])
        ->middleware('transaction.sign');

    Route::get('organization', [OrganizationController::class, 'show']);
    Route::put('organization', [OrganizationController::class, 'update']);

    Route::get('organization/users', [OrganizationUserController::class, 'index']);
    Route::post('organization/users', [OrganizationUserController::class, 'store']);
    Route::put('organization/users/{user}/roles', [OrganizationUserController::class, 'updateRoles'])
        ->whereNumber('user');
    Route::delete('organization/users/{user}', [OrganizationUserController::class, 'destroy'])
        ->whereNumber('user');

    Route::get('organization/representatives', [RepresentativeController::class, 'index']);
    Route::post('organization/representatives', [RepresentativeController::class, 'store']);
});
