<?php

declare(strict_types=1);

namespace App\Modules\Shared\Concerns;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Base for every module provider: wires migrations, routes and console commands
 * from a conventional location so individual modules stay boilerplate-free.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /** Absolute path to the module directory. */
    abstract protected function modulePath(): string;

    /** @return array<class-string, class-string> interface => implementation */
    protected function bindings(): array
    {
        return [];
    }

    /** @return array<class-string> Artisan command classes exposed by this module. */
    protected function consoleCommands(): array
    {
        return [];
    }

    /** @return array<class-string, array<class-string>> event => listeners */
    protected function listeners(): array
    {
        return [];
    }

    public function register(): void
    {
        foreach ($this->bindings() as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }
    }

    public function boot(): void
    {
        $migrations = $this->modulePath().'/Database/Migrations';
        if (is_dir($migrations)) {
            $this->loadMigrationsFrom($migrations);
        }

        $apiRoutes = $this->modulePath().'/Http/routes.php';
        if (is_file($apiRoutes)) {
            Route::prefix('api/v1')
                ->middleware('api')
                ->group($apiRoutes);
        }

        foreach ($this->listeners() as $event => $handlers) {
            foreach ($handlers as $handler) {
                $this->app['events']->listen($event, $handler);
            }
        }

        if ($this->app->runningInConsole() && $this->consoleCommands() !== []) {
            // ServiceProvider::commands() is the framework's registrar; our list
            // lives in consoleCommands() to avoid clashing with its signature.
            $this->commands($this->consoleCommands());
        }
    }
}
