<?php

declare(strict_types=1);

/*
 * Module providers.
 *
 * The list is discovered from the filesystem rather than hand-maintained: a
 * module whose provider is not registered still passes its own tests (its test
 * case boots it directly) but silently contributes no migrations, listeners or
 * routes in production. That failure mode has already bitten this project
 * once, so the registry is derived from what exists on disk.
 *
 * Load order follows the dependency graph in
 * docs/02-architecture/02-modules.md §2.2 for the modules that have one;
 * anything else is appended alphabetically. Order only affects migration
 * discovery and listener registration, since cross-module wiring resolves
 * lazily through the container.
 */

$ordered = [
    'Shared',
    'Identity',
    'Kyc',
    'Custody',
    'Ledger',
    'Pricing',
    'Risk',
    'Trading',
    'Settlement',
    'Counterparty',
    'Accounting',
    'Dispute',
    'Reputation',
    'Notification',
    'Reporting',
];

$modulePath = dirname(__DIR__).'/app/Modules';

$discovered = is_dir($modulePath)
    ? array_values(array_filter(
        scandir($modulePath) ?: [],
        static fn (string $entry): bool => $entry !== '.'
            && $entry !== '..'
            && is_dir($modulePath.'/'.$entry),
    ))
    : [];

// Known modules first, in dependency order; then anything new, alphabetically.
$remaining = array_diff($discovered, $ordered);
sort($remaining);

$providers = [App\Providers\AppServiceProvider::class];

foreach ([...$ordered, ...$remaining] as $module) {
    $class = "App\\Modules\\{$module}\\{$module}ServiceProvider";

    if (class_exists($class)) {
        $providers[] = $class;
    }
}

return $providers;
