<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Tests;

use App\Modules\Counterparty\Application\RelationService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The lost-update guarantee.
 *
 * A test process cannot run two real transactions against the same row and see
 * the interleaving, so concurrency is attacked from two directions instead:
 * the *shape* of the write (an accumulating upsert can never lose an update,
 * a read-modify-write always can), and a stale-read scenario that would produce
 * a wrong answer under read-modify-write and the right one here.
 */
final class ConcurrentApplyTradeTest extends CounterpartyTestCase
{
    #[Test]
    public function the_balance_write_is_a_single_accumulating_statement(): void
    {
        DB::enableQueryLog();

        $this->relations->applyTrade(101, 202, 250_000, -19_522_500_000);

        $queries = array_map(
            static fn (array $q): string => strtolower((string) $q['query']),
            DB::getQueryLog(),
        );

        DB::disableQueryLog();

        $balanceWrites = array_values(array_filter(
            $queries,
            static fn (string $q): bool => str_contains($q, 'counterparty_relations'),
        ));

        self::assertCount(2, $balanceWrites, 'exactly one write per direction');

        foreach ($balanceWrites as $query) {
            self::assertStringContainsString('on duplicate key update', $query);
            self::assertStringContainsString('gold_balance_mg + values(gold_balance_mg)', $query);
            self::assertStringNotContainsString('select', $query, 'a read-modify-write can lose updates');
        }
    }

    #[Test]
    public function an_update_applied_after_a_stale_read_is_not_lost(): void
    {
        $this->relations->applyTrade(101, 202, 100_000, 0);

        // Writer A reads the balance and then stalls (the classic setup for a
        // lost update): whatever it does next must not depend on this value.
        $staleRead = $this->goldBalance(101, 202);
        self::assertSame(100_000, $staleRead);

        // Writer B commits while A is stalled.
        $this->relations->applyTrade(101, 202, 30_000, 0);

        // Writer A now applies its delta. Under `UPDATE ... SET balance = ?`
        // computed from $staleRead this would store 150_000 and silently drop
        // writer B's 30_000.
        $this->relations->applyTrade(101, 202, 50_000, 0);

        self::assertSame(180_000, $this->goldBalance(101, 202));
        self::assertSame(-180_000, $this->goldBalance(202, 101));
    }

    #[Test]
    public function interleaved_writers_accumulate_every_delta(): void
    {
        // Two independent service instances standing in for two workers, their
        // calls interleaved one by one.
        $workerA = $this->app->make(RelationService::class);
        $workerB = $this->app->make(RelationService::class);

        for ($i = 0; $i < 200; $i++) {
            $workerA->applyTrade(101, 202, 1_000, 5_000_000, reference: "A-{$i}");
            $workerB->applyTrade(202, 101, 400, -2_000_000, reference: "B-{$i}");
        }

        // A added +1000 mg 200 times from 101's side; B added +400 mg 200 times
        // from 202's side, which is -400 mg for 101. The rial legs point the
        // same way once mirrored, so they add rather than cancel.
        self::assertSame(200 * (1_000 - 400), $this->goldBalance(101, 202));
        self::assertSame(-200 * (1_000 - 400), $this->goldBalance(202, 101));
        self::assertSame(200 * (5_000_000 + 2_000_000), $this->rialBalance(101, 202));

        $relation = $this->relations->relation(101, 202);
        self::assertSame(400, $relation?->total_trade_count, 'every call counted once');
        self::assertSame(200 * (1_000 + 400), $relation?->total_volume_mg);

        // Every one of the 800 movement rows (400 calls × 2 directions) is there.
        self::assertSame(800, DB::table('counterparty_movements')->count());
    }

    #[Test]
    public function the_relation_row_need_not_exist_before_the_first_write(): void
    {
        // The upsert removes the "who creates the row" race entirely: two
        // simultaneous first trades both succeed, one inserting and one
        // accumulating, in whichever order the database picks.
        $this->relations->applyTrade(301, 302, 10_000, 0, reference: 'first');
        $this->relations->applyTrade(301, 302, 10_000, 0, reference: 'second');

        self::assertSame(20_000, $this->goldBalance(301, 302));
        self::assertSame(1, DB::table('counterparty_relations')
            ->where('organization_id', 301)
            ->where('counterparty_org_id', 302)
            ->count());
    }
}
