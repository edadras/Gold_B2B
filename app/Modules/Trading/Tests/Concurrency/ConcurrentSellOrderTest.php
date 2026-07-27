<?php

declare(strict_types=1);

namespace App\Modules\Trading\Tests\Concurrency;

use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Shared\Exceptions\InsufficientBalanceException;
use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Infrastructure\Models\Order;
use App\Modules\Trading\Tests\TradingTestCase;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * Worked example 5 — the double-sell race, at the order-placement level.
 *
 * Organisation 184 holds 100 g and two processes each try to sell 80 g at the
 * same moment. Without a pessimistic lock both would read 100,000 mg, both
 * would pass their own check, and the balance would end at −60,000 mg. With
 * one, the second transaction blocks on the balance row and then sees 20,000 mg
 * left, so it is refused.
 *
 * Exactly one order must exist afterwards, and the balance must be 20,000 mg.
 *
 * Deliberately does NOT use RefreshDatabase: that trait wraps the test in a
 * transaction that is never committed, so a forked child would see an empty
 * database. The fixtures are migrated and committed for real before the fork.
 *
 * Pattern from docs/06-backend-laravel/02-implementation-guide.md §2.7.
 */
#[Group('ledger-invariants')]
final class ConcurrentSellOrderTest extends TradingTestCase
{
    private const STARTING_MG = 100_000;   // 100 g

    private const SELL_MG = 80_000;        // 80 g

    private const PRICE = 78_480_000;

    private const PROCESSES = 2;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->canFork()) {
            $this->markTestSkipped(
                'pcntl_fork / stream_socket_pair are unavailable in this PHP build, so worked '
                .'example 5 cannot be reproduced. Re-run on a CLI SAPI with ext-pcntl enabled — '
                .'this test must not be deleted.'
            );
        }

        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->setUpMarket(self::SELLER_ORG, self::BUYER_ORG);
        $this->depositGold(self::SELLER_ORG, self::STARTING_MG);

        $this->assertSame(self::STARTING_MG, $this->goldBalance(self::SELLER_ORG));
    }

    protected function tearDown(): void
    {
        // The suite's other tests roll their own data back; this one committed,
        // so it cleans up after itself.
        Artisan::call('migrate:fresh', ['--force' => true]);

        parent::tearDown();
    }

    #[Test]
    public function only_one_of_two_concurrent_sell_orders_can_succeed(): void
    {
        $results = $this->runConcurrently(self::PROCESSES, static function (): string {
            try {
                // The risk guard is replaced inside the child, since the
                // parent's container instance does not survive the fork.
                app()->instance(
                    \App\Modules\Risk\Contracts\RiskGuardInterface::class,
                    new \App\Modules\Trading\Tests\PermissiveRiskGuard,
                );

                app(\App\Modules\Trading\Application\PlaceOrderService::class)->place(
                    new \App\Modules\Trading\Application\Commands\PlaceOrderCommand(
                        organizationId: ConcurrentSellOrderTest::sellerOrg(),
                        userId: ConcurrentSellOrderTest::sellerUser(),
                        representativeId: null,
                        instrumentCode: ConcurrentSellOrderTest::instrumentCode(),
                        side: Side::SELL,
                        type: \App\Modules\Trading\Domain\OrderType::LIMIT,
                        timeInForce: \App\Modules\Trading\Domain\TimeInForce::DAY,
                        quantity: \App\Modules\Shared\ValueObjects\FineWeight::fromMilligrams(80_000),
                        price: \App\Modules\Shared\ValueObjects\PricePerFineGram::fromRial(78_480_000),
                    )
                );

                return 'ok';
            } catch (InsufficientBalanceException) {
                return 'rejected';
            } catch (Throwable $e) {
                return 'error:'.$e::class.':'.$e->getMessage();
            }
        });

        $this->assertCount(self::PROCESSES, $results, 'A child process died without reporting');

        $succeeded = count(array_filter($results, static fn (string $r): bool => $r === 'ok'));
        $rejected = count(array_filter($results, static fn (string $r): bool => $r === 'rejected'));

        $this->assertSame(1, $succeeded, 'Exactly one sell must win. Results: '.json_encode($results));
        $this->assertSame(1, $rejected, 'Results: '.json_encode($results));

        // The document's expected end state.
        $this->assertSame(self::STARTING_MG - self::SELL_MG, $this->goldBalance(self::SELLER_ORG));
        $this->assertSame(self::SELL_MG, $this->goldBalance(self::SELLER_ORG, Bucket::RESERVED));

        // The loser left nothing behind: its order row rolled back with its
        // reservation attempt.
        $orders = Order::query()->where('organization_id', self::SELLER_ORG)->get();
        $this->assertCount(1, $orders);
        $this->assertSame(OrderStatus::OPEN, $orders[0]->status);
        $this->assertSame(self::SELL_MG, $orders[0]->reserved_amount);

        $this->assertSystemConserved();
    }

    // The child closure is static and cannot reach protected constants, so the
    // fixture values are exposed through these.
    public static function sellerOrg(): int
    {
        return self::SELLER_ORG;
    }

    public static function sellerUser(): int
    {
        return self::SELLER_USER;
    }

    public static function instrumentCode(): string
    {
        return self::INSTRUMENT;
    }

    /**
     * Run $count copies of $job in forked processes and collect their results.
     *
     * The parent drops its database connection before forking so no child
     * inherits a live MySQL socket — a shared socket between processes corrupts
     * both sides of the protocol. Each child opens its own lazily.
     *
     * @param  Closure(): string  $job
     * @return array<int, string>
     */
    private function runConcurrently(int $count, Closure $job): array
    {
        $sockets = [];
        $pids = [];

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
