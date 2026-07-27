<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Modules\Admin\Contracts\AmlAdminPort;
use App\Modules\Admin\Contracts\AuditLogPort;
use App\Modules\Admin\Contracts\DisputeAdminPort;
use App\Modules\Admin\Contracts\KycAdminPort;
use App\Modules\Admin\Contracts\LedgerAdjustmentPoster;
use App\Modules\Admin\Contracts\LedgerAdminPort;
use App\Modules\Admin\Contracts\OrganizationAdminPort;
use App\Modules\Admin\Contracts\PlatformMetricsPort;
use App\Modules\Admin\Contracts\SettlementAdminPort;
use App\Modules\Admin\Contracts\VaultAdminPort;
use App\Modules\Admin\Http\Middleware\AuthenticateAdmin;
use App\Modules\Admin\Http\Middleware\IdleSessionTimeout;
use App\Modules\Admin\Http\Middleware\PlatformStaffOnly;
use App\Modules\Admin\Http\Middleware\RequirePlatformPermission;
use App\Modules\Admin\Infrastructure\Tables\TableAmlAdminAdapter;
use App\Modules\Admin\Infrastructure\Tables\TableAuditLogAdapter;
use App\Modules\Admin\Infrastructure\Tables\TableDisputeAdminAdapter;
use App\Modules\Admin\Infrastructure\Tables\TableKycAdminAdapter;
use App\Modules\Admin\Infrastructure\Tables\TableLedgerAdjustmentPoster;
use App\Modules\Admin\Infrastructure\Tables\TableLedgerAdminAdapter;
use App\Modules\Admin\Infrastructure\Tables\TableOrganizationAdminAdapter;
use App\Modules\Admin\Infrastructure\Tables\TablePlatformMetricsAdapter;
use App\Modules\Admin\Infrastructure\Tables\TableSettlementAdminAdapter;
use App\Modules\Admin\Infrastructure\Tables\TableVaultAdminAdapter;
use App\Modules\Shared\Concerns\ModuleServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/**
 * The operator admin panel.
 *
 * Two departures from the standard module provider, both deliberate:
 *
 *   1. routes are mounted at `/admin` with the `web` group instead of at
 *      `api/v1` with `api`. The panel is server-rendered HTML with a session
 *      cookie; ModuleServiceProvider's default would give it JSON error
 *      rendering and no session at all. The route file is therefore called
 *      `admin-routes.php`, so the base class's `Http/routes.php` convention
 *      does not pick it up as well.
 *
 *   2. every cross-module read is bound here to an adapter that queries by
 *      table name. Admin may depend on Shared and Identity only, so it reaches
 *      Ledger, Kyc, Settlement, Risk, Dispute and Custody through ports it
 *      declares itself. Each binding below is a contract those modules do not
 *      publish yet; when one appears, the line changes and the adapter goes.
 */
final class AdminServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    /** @return array<class-string, class-string> */
    protected function bindings(): array
    {
        return [
            // Ports Admin declares because the owning module publishes no read.
            PlatformMetricsPort::class => TablePlatformMetricsAdapter::class,
            LedgerAdminPort::class => TableLedgerAdminAdapter::class,
            LedgerAdjustmentPoster::class => TableLedgerAdjustmentPoster::class,
            KycAdminPort::class => TableKycAdminAdapter::class,
            SettlementAdminPort::class => TableSettlementAdminAdapter::class,
            AmlAdminPort::class => TableAmlAdminAdapter::class,
            DisputeAdminPort::class => TableDisputeAdminAdapter::class,
            VaultAdminPort::class => TableVaultAdminAdapter::class,
            AuditLogPort::class => TableAuditLogAdapter::class,
            OrganizationAdminPort::class => TableOrganizationAdminAdapter::class,
        ];
    }

    public function boot(): void
    {
        // Migrations and listeners from the base class; its api/v1 route hook
        // finds no Http/routes.php here and does nothing.
        parent::boot();

        $this->loadViewsFrom(__DIR__.'/Resources/views', 'admin');

        $this->registerMiddlewareAliases();
        $this->registerRoutes();
    }

    private function registerMiddlewareAliases(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('admin.auth', AuthenticateAdmin::class);
        $router->aliasMiddleware('admin.idle', IdleSessionTimeout::class);
        $router->aliasMiddleware('admin.staff', PlatformStaffOnly::class);
        $router->aliasMiddleware('admin.can', RequirePlatformPermission::class);
    }

    private function registerRoutes(): void
    {
        $routes = __DIR__.'/Http/admin-routes.php';

        if (! is_file($routes)) {
            return;
        }

        Route::prefix('admin')
            ->name('admin.')
            ->middleware($this->webMiddleware())
            ->group($routes);
    }

    /**
     * The `web` group, or its contents inlined.
     *
     * Referencing the group by name is the normal thing to do, but this
     * provider may be registered after the HTTP kernel has been built (as it is
     * in the module's own tests, which register it explicitly rather than
     * relying on bootstrap/providers.php). Falling back to the group's resolved
     * middleware list keeps sessions and CSRF in place either way.
     *
     * @return array<int, string>|string
     */
    private function webMiddleware(): array|string
    {
        if (! $this->app->bound(Kernel::class)) {
            return 'web';
        }

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $groups = $router->getMiddlewareGroups();

        return array_key_exists('web', $groups) ? 'web' : [];
    }
}
