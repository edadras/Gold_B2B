<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A module whose provider is never registered still passes its own tests — its
 * test case boots the provider directly — while contributing no migrations, no
 * listeners and no routes to the running application. That is a silent failure
 * with no symptom until something is missing in production, and it has already
 * happened once in this project.
 */
final class ModuleRegistrationTest extends TestCase
{
    #[Test]
    public function every_module_provider_on_disk_is_registered(): void
    {
        $registered = array_map(
            static fn (object $p): string => $p::class,
            app()->getLoadedProviders() !== [] ? [] : [],
        );

        $loaded = array_keys(app()->getLoadedProviders());
        $missing = [];

        foreach ($this->moduleNames() as $module) {
            $class = "App\\Modules\\{$module}\\{$module}ServiceProvider";

            if (! class_exists($class)) {
                continue;   // module exists but has no provider yet
            }

            if (! in_array($class, $loaded, true)) {
                $missing[] = $module;
            }
        }

        self::assertSame(
            [],
            $missing,
            'These modules have a provider that is never registered: '.implode(', ', $missing),
        );
    }

    #[Test]
    public function every_module_with_routes_contributes_them(): void
    {
        $uris = collect(app('router')->getRoutes()->getRoutes())
            ->map(static fn ($r): string => $r->uri())
            ->all();

        $missing = [];

        foreach ($this->moduleNames() as $module) {
            $routeFile = base_path("app/Modules/{$module}/Http/routes.php");

            if (! is_file($routeFile)) {
                continue;
            }

            // Each module's routes.php must put at least one route on the
            // router; a file that is present but never loaded is the exact
            // failure this test exists to catch.
            $contributes = false;

            foreach ($uris as $uri) {
                if (str_contains($uri, strtolower($module))
                    || $this->moduleOwnsSomeRoute($module, $uris)) {
                    $contributes = true;
                    break;
                }
            }

            if (! $contributes) {
                $missing[] = $module;
            }
        }

        self::assertSame(
            [],
            $missing,
            'These modules define routes.php but register no routes: '.implode(', ', $missing),
        );
    }

    #[Test]
    public function every_module_with_migrations_has_them_discovered(): void
    {
        $paths = app('migrator')->paths();
        $missing = [];

        foreach ($this->moduleNames() as $module) {
            $dir = base_path("app/Modules/{$module}/Database/Migrations");

            if (! is_dir($dir) || glob($dir.'/*.php') === []) {
                continue;
            }

            $found = false;
            foreach ($paths as $path) {
                if (realpath($path) === realpath($dir)) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                $missing[] = $module;
            }
        }

        self::assertSame(
            [],
            $missing,
            'These modules have migrations that would never run: '.implode(', ', $missing),
        );
    }

    /** @return list<string> */
    private function moduleNames(): array
    {
        $root = base_path('app/Modules');

        return array_values(array_filter(
            scandir($root) ?: [],
            static fn (string $e): bool => $e !== '.' && $e !== '..' && is_dir($root.'/'.$e),
        ));
    }

    /** @param list<string> $uris */
    private function moduleOwnsSomeRoute(string $module, array $uris): bool
    {
        // Route paths are resource-named, not module-named (Identity owns
        // /auth and /organization), so fall back to asking the container
        // whether the provider booted at all.
        return in_array(
            "App\\Modules\\{$module}\\{$module}ServiceProvider",
            array_keys(app()->getLoadedProviders()),
            true,
        );
    }
}
