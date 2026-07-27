<?php

declare(strict_types=1);

/*
 * Module providers, listed in dependency order.
 *
 * Shared has no dependencies and must load first. The rest follow the module
 * dependency graph in docs/02-architecture/02-modules.md §2.2; the order only
 * matters for migration discovery and event-listener registration, since all
 * cross-module wiring goes through container bindings resolved lazily.
 *
 * Modules are brought online incrementally, so a provider that does not exist
 * yet is skipped rather than listed by hand.
 */

$modules = [
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

$providers = [App\Providers\AppServiceProvider::class];

foreach ($modules as $module) {
    $class = "App\\Modules\\{$module}\\{$module}ServiceProvider";

    if (class_exists($class)) {
        $providers[] = $class;
    }
}

return $providers;
