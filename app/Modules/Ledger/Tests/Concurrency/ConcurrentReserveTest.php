<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Concurrency;

use App\Modules\Ledger\Contracts\GoldLedgerInterface;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Tests\LedgerTestCase;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Exceptions\InsufficientBalanceException;
use App\Modules\Shared\ValueObjects\FineWeight;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * Worked example 5 / docs §3.10 — the double-spend race.
 *
 * Five processes each try to reserve 80 g against a 100 g balance. Exactly one
 * may win: the pessimistic lock on the balance row serialises them, and the
 * losers see the already-decremented balance.
 *
 * Deliberately does NOT use RefreshDatabase: that trait wraps the test in a
 * transaction that is never committed, so forked children would see an empty
 * ledger. The database is migrated and seeded for real, and the rows are
 * committed before the fork.
 *
 * Pattern from docs/06-backend-laravel/02-implementation-guide.md §2.7.
 */
#[Group('ledger-concurrency')]
final class ConcurrentReserveTest extends LedgerTestCase
{
    private const ORG = 184;

    private const STARTING_MG = 100_000;   // 100 g

    private const RESERVE_MG = 80_000;     // 80 g

    private const PROCESSES = 5;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->canFork()) {
            $this->markTestSkipped(
                'pcntl_fork / stream_socket_pair are unavailable in this PHP build, so the '
                .'oversell race cannot be reproduced. Re-run on a CLI SAPI with ext-pcntl '
                .'enabled — this test must not be deleted.'
            );
        }

        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->setUpLedger(self::ORG);
        $this->depositGold(self::ORG, self::STARTING_MG);

        $this->assertSame(self::STARTING_MG, $this->goldBalance(self::ORG));
    }

    /**
     * This test commits its rows on purpose — forked children run in their own
     * connections and cannot see an uncommitted transaction. That means nothing
     * rolls back afterwards, so the data must be cleared explicitly or it leaks
     * into every test that runs later in the suite.
     */
    protected function tearDown(): void
    {
        if ($this->canFork()) {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }

        parent::tearDown();
    }

    #[Test]
    public function only_one_of_five_concurrent_reservations_can_succeed(): void
    {
        $results = $this->runConcurrently(self::PROCESSES, static function (): string {
            try {
                app(GoldLedgerInterface::class)->reserve(
                    self::ORG,
                    FineWeight::fromMilligrams(self::RESERVE_MG),
                    LedgerReference::test(),
                );

                return 'ok';
            } catch (InsufficientBalanceException) {
                return 'rejected';
            } catch (DomainException $e) {
                return 'domain:'.$e->errorCode();
            } catch (Throwable $e) {
                return 'error:'.$e::class.':'.$e->getMessage();
            }
        });

        $this->assertCount(self::PROCESSES, $results, 'A child process died without reporting');

        $succeeded = count(array_filter($results, static fn (string $r): bool => $r === 'ok'));
        $rejected = count(array_filter($results, static fn (string $r): bool => $r === 'rejected'));

        $this->assertSame(1, $succeeded, 'Exactly one reservation must win. Results: '.json_encode($results));
        $this->assertSame(self::PROCESSES - 1, $rejected, 'Results: '.json_encode($results));

        // And the ledger is exactly as one successful reservation would leave it.
        $this->assertSame(self::STARTING_MG - self::RESERVE_MG, $this->goldBalance(self::ORG));
        $this->assertSame(self::RESERVE_MG, $this->goldBalance(self::ORG, Bucket::RESERVED));

        $reserveEntries = DB::table('ledger_entries')->where('entry_type', 'RESERVE')->count();
        $this->assertSame(2, $reserveEntries, 'A losing transaction left entries behind');

        $report = $this->reconciliation()->reconcile(null, true);
        $this->assertTrue($report['healthy'], json_encode($report));
    }

    /**
     * Run $count copies of $job in forked processes and collect their results.
     *
     * The parent drops its database connection before forking so no child
     * inherits a live MySQL socket — a shared socket between processes corrupts
     * both sides of the protocol. Each child opens its own connection lazily.
     *
     * @param  Closure(): string  $job
     * @return array<int, string>
     */
    private function runConcurrently(int $count, Closure $job): array
    {
        $sockets = [];
        $pids = [];

        // No live PDO may cross the fork boundary.
        DB::disconnect();

        for ($i = 0; $i < $count; $i++) {
            [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('pcntl_fork() failed');
            }

            if ($pid === 0) {
                // ── child ──
                fclose($parent);

                try {
                    $result = $job();
                } catch (Throwable $e) {
                    $result = 'crash:'.$e->getMessage();
                }

                fwrite($child, $result);
                fclose($child);

                // exit() rather than return: the child must not run PHPUnit's
                // shutdown, reporting or teardown.
                exit(0);
            }

            // ── parent ──
            fclose($child);
            $sockets[] = $parent;
            $pids[] = $pid;
        }

        $results = [];

        foreach ($sockets as $socket) {
            $payload = stream_get_contents($socket);
            fclose($socket);

            if (is_string($payload) && $payload !== '') {
                $results[] = $payload;
            }
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        return $results;
    }

    private function canFork(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('stream_socket_pair')
            && PHP_SAPI === 'cli';
    }
}
