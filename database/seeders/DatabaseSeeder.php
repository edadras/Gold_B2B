<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Root seeder.
 *
 * Reference data lives with the module that owns it, so this only sequences the
 * module seeders. Order matters: ledger system accounts and the RBAC matrix must
 * exist before anything that references them.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $seeders = [
            \App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder::class,
            \App\Modules\Ledger\Database\Seeders\LedgerSystemAccountsSeeder::class,
            \App\Modules\Shared\Database\Seeders\SystemSettingsSeeder::class,
            \App\Modules\Pricing\Database\Seeders\InstrumentsSeeder::class,
            \App\Modules\Risk\Database\Seeders\AmlRulesSeeder::class,
            \App\Modules\Accounting\Database\Seeders\ChartOfAccountsSeeder::class,
        ];

        foreach ($seeders as $seeder) {
            // Modules are brought online incrementally; skip any not yet present.
            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }
    }
}
