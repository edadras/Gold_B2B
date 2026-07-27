<?php

declare(strict_types=1);

use App\Modules\Admin\Http\Controllers\AmlController;
use App\Modules\Admin\Http\Controllers\AssetController;
use App\Modules\Admin\Http\Controllers\AuditLogController;
use App\Modules\Admin\Http\Controllers\AuthController;
use App\Modules\Admin\Http\Controllers\DashboardController;
use App\Modules\Admin\Http\Controllers\DisputeController;
use App\Modules\Admin\Http\Controllers\KycController;
use App\Modules\Admin\Http\Controllers\LedgerAdjustmentController;
use App\Modules\Admin\Http\Controllers\LedgerReconciliationController;
use App\Modules\Admin\Http\Controllers\OrganizationController;
use App\Modules\Admin\Http\Controllers\SettingsController;
use App\Modules\Admin\Http\Controllers\SettlementController;
use App\Modules\Admin\Http\Controllers\VaultController;
use Illuminate\Support\Facades\Route;

/*
 * Operator panel routes. Mounted under /admin by AdminServiceProvider with the
 * `web` middleware group — session cookies, CSRF, no api/v1 prefix.
 *
 * The file is NOT named routes.php on purpose: ModuleServiceProvider would then
 * mount it under api/v1 with the `api` group, which is precisely the wrong
 * shape for a server-rendered panel.
 *
 * Layering, outermost first:
 *   web            → session + CSRF
 *   admin.auth     → signed in at all
 *   admin.idle     → 30-minute idle logout (§1.11)
 *   admin.staff    → platform staff only; a member gets 403 (§1.11)
 *   admin.can:…    → per-screen permission; this is what keeps AML separate
 *
 * There is no DELETE route anywhere below. §1.11: «بدون امکان حذف هیچ رکورد
 * مالی» — and no route here removes any record at all, financial or otherwise.
 */

Route::get('assets/app.css', [AssetController::class, 'stylesheet'])->name('assets.css');

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.submit')
        ->middleware('throttle:auth');
});

Route::middleware(['admin.auth', 'admin.idle', 'admin.staff'])->group(function (): void {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // ── KYC ──────────────────────────────────────────────────────────────────
    Route::middleware('admin.can:platform.kyc.review')->group(function (): void {
        Route::get('kyc', [KycController::class, 'index'])->name('kyc.index');
        Route::get('kyc/{organization}', [KycController::class, 'show'])
            ->whereNumber('organization')->name('kyc.show');
        Route::get('kyc/{organization}/documents/{document}', [KycController::class, 'document'])
            ->whereNumber(['organization', 'document'])->name('kyc.document');
        Route::post('kyc/{organization}/decision', [KycController::class, 'decide'])
            ->whereNumber('organization')->name('kyc.decide');
    });

    // ── settlements ──────────────────────────────────────────────────────────
    Route::middleware('admin.can:platform.settlement.manage,platform.support.view')->group(function (): void {
        Route::get('settlements', [SettlementController::class, 'index'])->name('settlements.index');
        Route::get('settlements/{settlement}', [SettlementController::class, 'show'])
            ->whereNumber('settlement')->name('settlements.show');
    });

    // ── ledger ───────────────────────────────────────────────────────────────
    Route::middleware('admin.can:platform.settlement.manage,platform.audit.view')->group(function (): void {
        Route::get('ledger/reconciliation', [LedgerReconciliationController::class, 'index'])
            ->name('ledger.reconciliation');
        Route::get('ledger/reconciliation/organization', [LedgerReconciliationController::class, 'organization'])
            ->name('ledger.reconciliation.organization');
        Route::get('ledger/accounts/{account}', [LedgerReconciliationController::class, 'account'])
            ->whereNumber('account')->name('ledger.account');
    });

    /*
     * The manual adjustment. Behind `platform.ledger.adjust`, which only
     * SETTLEMENT_OFFICER and PLATFORM_ADMIN hold — and the approve route is
     * additionally gated in ManualAdjustmentService, because a permission check
     * cannot express "a different person from the one who raised it".
     */
    Route::middleware('admin.can:platform.ledger.adjust')->group(function (): void {
        Route::get('ledger/adjustments', [LedgerAdjustmentController::class, 'index'])
            ->name('ledger.adjustments.index');
        Route::get('ledger/adjustments/create', [LedgerAdjustmentController::class, 'create'])
            ->name('ledger.adjustments.create');
        Route::post('ledger/adjustments', [LedgerAdjustmentController::class, 'store'])
            ->name('ledger.adjustments.store');
        Route::get('ledger/adjustments/{adjustment}', [LedgerAdjustmentController::class, 'show'])
            ->whereNumber('adjustment')->name('ledger.adjustments.show');
        Route::post('ledger/adjustments/{adjustment}/approve', [LedgerAdjustmentController::class, 'approve'])
            ->whereNumber('adjustment')->name('ledger.adjustments.approve');
        Route::post('ledger/adjustments/{adjustment}/reject', [LedgerAdjustmentController::class, 'reject'])
            ->whereNumber('adjustment')->name('ledger.adjustments.reject');
    });

    // ── AML — its own permission, per §1.11 ─────────────────────────────────
    Route::middleware('admin.can:platform.aml.manage')->group(function (): void {
        Route::get('aml', [AmlController::class, 'index'])->name('aml.index');
        Route::get('aml/{flag}', [AmlController::class, 'show'])
            ->whereNumber('flag')->name('aml.show');
        Route::post('aml/{flag}/decision', [AmlController::class, 'decide'])
            ->whereNumber('flag')->name('aml.decide');
    });

    // ── disputes ─────────────────────────────────────────────────────────────
    Route::middleware('admin.can:platform.support.view,platform.settlement.manage')->group(function (): void {
        Route::get('disputes', [DisputeController::class, 'index'])->name('disputes.index');
        Route::get('disputes/{dispute}', [DisputeController::class, 'show'])
            ->whereNumber('dispute')->name('disputes.show');
        Route::post('disputes/{dispute}/mediator', [DisputeController::class, 'assignMediator'])
            ->whereNumber('dispute')->name('disputes.mediator');
    });

    // ── vaults ───────────────────────────────────────────────────────────────
    Route::middleware('admin.can:platform.vault.manage,platform.support.view')->group(function (): void {
        Route::get('vaults', [VaultController::class, 'index'])->name('vaults.index');
        Route::get('vaults/{vault}', [VaultController::class, 'show'])
            ->whereNumber('vault')->name('vaults.show');
    });

    // ── organisations ────────────────────────────────────────────────────────
    Route::middleware('admin.can:platform.support.view')->group(function (): void {
        Route::get('organizations', [OrganizationController::class, 'index'])
            ->name('organizations.index');
        Route::get('organizations/{organization}', [OrganizationController::class, 'show'])
            ->whereNumber('organization')->name('organizations.show');
    });

    Route::middleware('admin.can:platform.organization.suspend')->group(function (): void {
        Route::post('organizations/{organization}/status', [OrganizationController::class, 'updateStatus'])
            ->whereNumber('organization')->name('organizations.status');
    });

    // ── audit log ────────────────────────────────────────────────────────────
    Route::middleware('admin.can:platform.audit.view')->group(function (): void {
        Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
    });

    // ── settings ─────────────────────────────────────────────────────────────
    Route::middleware('admin.can:platform.admin.all')->group(function (): void {
        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::post('settings', [SettingsController::class, 'update'])->name('settings.update');
    });
});
