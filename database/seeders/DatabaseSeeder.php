<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Ledger\Database\Seeders\LedgerSystemAccountsSeeder;
use App\Modules\Risk\Database\Seeders\AmlRulesSeeder;
use App\Modules\Risk\Database\Seeders\TradingLimitsSeeder;
use App\Modules\Shared\Database\Seeders\SystemSettingsSeeder;
use App\Modules\Trading\Database\Seeders\InstrumentsSeeder;
use Illuminate\Database\Seeder;

/**
 * Root seeder.
 *
 * Reference data lives with the module that owns it, so this only sequences the
 * module seeders. Order matters: ledger system accounts and the RBAC matrix must
 * exist before anything that references them.
 *
 * The class_exists() guard lets a deployment slice run without a module. It also
 * turns a mistyped class name into a silent no-op, which is how the instruments
 * seeder came to be listed under the wrong module and skipped on every
 * `db:seed` — a fresh database with no tradable instruments and nothing said.
 * DatabaseSeederTest pins the list against the classes actually on disk so the
 * guard can only ever skip a module that is genuinely absent.
 *
 * Not listed: Accounting's chart of accounts. It is inserted by its own
 * migration, because the posting rules key off the account codes and a database
 * that has been migrated but not seeded would otherwise be unusable rather than
 * merely empty.
 */
final class DatabaseSeeder extends Seeder
{
    /** @var list<class-string<Seeder>> */
    public const SEEDERS = [
        RolesAndPermissionsSeeder::class,
        LedgerSystemAccountsSeeder::class,
        SystemSettingsSeeder::class,
        InstrumentsSeeder::class,
        TradingLimitsSeeder::class,
        AmlRulesSeeder::class,
    ];

    public function run(): void
    {
        foreach (self::SEEDERS as $seeder) {
            // Modules are brought online incrementally; skip any not yet present.
            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }
    }
}
