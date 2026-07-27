<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Risk\Database\Seeders\RiskDatabaseSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * `db:seed` has to actually seed.
 *
 * DatabaseSeeder skips a seeder whose class does not exist, so that a
 * deployment slice can run without a module. The cost of that leniency is that
 * a mistyped or moved class name is indistinguishable from an absent module,
 * and it has already cost something real: the instruments seeder was listed
 * under Pricing when it lives in Trading, so every `db:seed` since produced a
 * database with no tradable instruments and said nothing about it.
 *
 * These tests take the two halves of the problem: every name in the list must
 * resolve, and every seeder on disk must be in the list or deliberately absent
 * from it.
 */
final class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seeders that exist but are deliberately not sequenced by the root seeder.
     *
     * @var array<class-string, string>
     */
    private const NOT_SEQUENCED = [
        RiskDatabaseSeeder::class => 'An aggregate over the other Risk seeders; calling it too would seed them twice.',
    ];

    #[Test]
    public function every_seeder_in_the_list_exists(): void
    {
        foreach (DatabaseSeeder::SEEDERS as $seeder) {
            $this->assertTrue(
                class_exists($seeder),
                "{$seeder} is listed in DatabaseSeeder but does not exist. The class_exists guard "
                .'means this would be skipped in silence on a real deployment.',
            );
        }
    }

    #[Test]
    public function every_module_seeder_on_disk_is_either_sequenced_or_deliberately_not(): void
    {
        foreach ($this->seedersOnDisk() as $seeder) {
            $known = in_array($seeder, DatabaseSeeder::SEEDERS, true)
                || array_key_exists($seeder, self::NOT_SEQUENCED);

            $this->assertTrue(
                $known,
                "{$seeder} exists but `db:seed` never calls it. Add it to DatabaseSeeder::SEEDERS, "
                .'or to this test\'s NOT_SEQUENCED list with the reason.',
            );
        }
    }

    #[Test]
    public function seeding_a_fresh_database_produces_a_usable_platform(): void
    {
        $this->seed(DatabaseSeeder::class);

        // The four things without which nothing else in the system can run: an
        // RBAC matrix, the system accounts every transaction group posts
        // against, something to trade, and the risk ceilings that gate it.
        $this->assertGreaterThan(0, DB::table('roles')->count(), 'no roles');
        $this->assertGreaterThan(0, DB::table('ledger_accounts')->where('organization_id', 0)->count(), 'no system accounts');
        $this->assertGreaterThan(0, DB::table('instruments')->count(), 'no instruments — nothing is tradable');
        $this->assertGreaterThan(0, DB::table('trading_limits')->count(), 'no risk limits');

        // Seeded by its migration rather than by a seeder, and just as required.
        $this->assertGreaterThan(0, DB::table('chart_of_accounts')->count(), 'no chart of accounts');
    }

    #[Test]
    public function seeding_twice_changes_nothing(): void
    {
        // `db:seed` is run by hand on live systems often enough that it must be
        // idempotent — a second run that duplicated the system accounts would
        // break every balance in the ledger.
        $this->seed(DatabaseSeeder::class);

        $counts = $this->referenceCounts();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($counts, $this->referenceCounts());
    }

    /** @return array<string, int> */
    private function referenceCounts(): array
    {
        $counts = [];

        foreach (['roles', 'permissions', 'ledger_accounts', 'instruments', 'trading_limits', 'chart_of_accounts'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * @return list<class-string<Seeder>>
     */
    private function seedersOnDisk(): array
    {
        $found = [];
        $root = dirname(__DIR__, 2).'/app/Modules';

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            if (! str_contains($path, '/Database/Seeders/')) {
                continue;
            }

            $class = 'App\\Modules\\'.str_replace(
                '/',
                '\\',
                substr($path, strlen($root) + 1, -strlen('.php')),
            );

            if (class_exists($class) && is_subclass_of($class, Seeder::class)) {
                $found[] = $class;
            }
        }

        sort($found);

        return $found;
    }
}
