<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Loads routes/api.php.
     *
     * bootstrap/app.php's withRouting() declares only web, console and health,
     * and bootstrap/providers.php is off limits — so the platform-level API
     * routes (health, meta, openapi.json) are registered from here. Module
     * routes come from ModuleServiceProvider and are unaffected.
     */
    public function boot(): void
    {
        $apiRoutes = base_path('routes/api.php');

        if (is_file($apiRoutes)) {
            Route::group([], $apiRoutes);
        }
    }
}
