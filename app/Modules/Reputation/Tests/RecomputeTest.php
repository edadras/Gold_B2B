<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Tests;

use App\Modules\Reputation\Application\RecomputeService;
use App\Modules\Reputation\Contracts\CounterpartyCounter;
use App\Modules\Reputation\Domain\VerificationTier;
use App\Modules\Reputation\Infrastructure\RelationTableCounterpartyCounter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * §14.6 — `distinct_counterparties` cannot be incremented, so it is rebuilt.
 *
 * The relation table belongs to the Counterparty module, which this module may
 * not depend on. The tests below therefore create a table of the same shape
 * (a stand-in, exactly as the real schema declares it) and exercise the reader
 * against it, rather than importing anything from that module.
 */
final class RecomputeTest extends ReputationTestCase
{
    private RecomputeService $recompute;

    /** True when this test created the stand-in and therefore owns cleaning it up. */
    private bool $createdStandIn = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recompute = $this->app->make(RecomputeService::class);
    }

    /**
     * DDL commits implicitly in MySQL, so a stand-in table would survive the
     * RefreshDatabase rollback and leak into the next test. Only a table this
     * test created is dropped — when the Counterparty module is deployed, the
     * real migrated table is used and must be left alone.
     */
    protected function tearDown(): void
    {
        if ($this->createdStandIn) {
            Schema::dropIfExists('counterparty_relations');
            $this->createdStandIn = false;
        }

        parent::tearDown();
    }

    #[Test]
    public function distinct_counterparties_is_rebuilt_from_the_relation_table(): void
    {
        $this->createRelationTableStandIn();

        $this->seedMember(11);
        $this->seedMember(22);

        $this->relation(11, 201, tradeCount: 4);
        $this->relation(11, 202, tradeCount: 9);
        $this->relation(11, 203, tradeCount: 1);
        // A relation created by setting a credit limit, never traded: not a
        // business relationship and must not count.
        $this->relation(11, 204, tradeCount: 0);
        $this->relation(22, 201, tradeCount: 2);

        $changed = $this->recompute->recomputeDistinctCounterparties();

        self::assertSame(2, $changed);
        self::assertSame(3, (int) DB::table('reputation_stats')->where('organization_id', 11)->value('distinct_counterparties'));
        self::assertSame(1, (int) DB::table('reputation_stats')->where('organization_id', 22)->value('distinct_counterparties'));
    }

    #[Test]
    public function a_stale_count_is_corrected_downwards_too(): void
    {
        $this->createRelationTableStandIn();

        $this->seedMember(11, distinctCounterparties: 50);

        $this->recompute->recomputeDistinctCounterparties();

        self::assertSame(0, (int) DB::table('reputation_stats')->where('organization_id', 11)->value('distinct_counterparties'));
    }

    #[Test]
    public function a_missing_relation_table_yields_zero_rather_than_an_error(): void
    {
        // Counterparty not deployed in this slice: the tier rules simply see no
        // spread, which can only ever hold a member back. The table is renamed
        // rather than dropped so the rest of the suite keeps its schema.
        $present = Schema::hasTable('counterparty_relations');

        if ($present) {
            DB::statement('RENAME TABLE counterparty_relations TO counterparty_relations_hidden');
        }

        try {
            self::assertFalse(Schema::hasTable('counterparty_relations'));

            $counter = new RelationTableCounterpartyCounter;

            self::assertSame(0, $counter->distinctCounterparties(11));
            self::assertSame([], $counter->allDistinctCounterparties());
        } finally {
            if ($present) {
                DB::statement('RENAME TABLE counterparty_relations_hidden TO counterparty_relations');
            }
        }
    }

    #[Test]
    public function day_rows_are_folded_into_weeks_and_months(): void
    {
        $this->seedMember(11);

        foreach (['2026-03-02', '2026-03-03', '2026-03-10'] as $day) {
            $this->stats->recordSettlement(11, 250_000, onTime: true, settlementMinutes: 5, occurredAt: $day.' 10:00:00');
        }

        $this->stats->recordSettlement(11, 250_000, onTime: false, settlementMinutes: 900, occurredAt: '2026-03-03 11:00:00');

        $written = $this->recompute->rollUpPeriods(Carbon::parse('2026-03-31'));

        self::assertGreaterThan(0, $written);

        $month = DB::table('reputation_periods')
            ->where('organization_id', 11)
            ->where('period_type', 'MONTH')
            ->where('period_start', '2026-03-01')
            ->first();

        self::assertNotNull($month);
        self::assertSame(4, (int) $month->trades);
        self::assertSame(1_000_000, (int) $month->volume_mg);
        self::assertSame(7_500, (int) $month->on_time_rate_bps, '3 of 4 on time');

        // The week of 2 March holds three of the four; the week of 9 March one.
        $weeks = DB::table('reputation_periods')
            ->where('organization_id', 11)
            ->where('period_type', 'WEEK')
            ->orderBy('period_start')
            ->get();

        self::assertCount(2, $weeks);
        self::assertSame(3, (int) $weeks[0]->trades);
        self::assertSame(1, (int) $weeks[1]->trades);
    }

    #[Test]
    public function the_command_recomputes_and_promotes_in_one_pass(): void
    {
        $this->createRelationTableStandIn();

        $this->seedMember(11, memberSinceDays: 400);

        for ($i = 0; $i < 60; $i++) {
            $this->stats->recordSettlement(11, 100_000, onTime: true, settlementMinutes: 20);
        }

        for ($counterparty = 201; $counterparty <= 208; $counterparty++) {
            $this->relation(11, $counterparty, tradeCount: 7);
        }

        // Before the pass the member looks like a one-counterparty shop.
        self::assertSame(VerificationTier::BRONZE, $this->tierOf(11));

        $this->artisan('reputation:recompute')
            ->expectsOutputToContain('Promoted 1 member(s)')
            ->assertExitCode(0);

        self::assertSame(8, (int) DB::table('reputation_stats')->where('organization_id', 11)->value('distinct_counterparties'));
        self::assertSame(VerificationTier::SILVER, $this->tierOf(11));
    }

    #[Test]
    public function the_recompute_command_has_no_way_to_demote(): void
    {
        $definition = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)
            ->all()['reputation:recompute']
            ->getDefinition();

        foreach (array_keys($definition->getOptions()) as $option) {
            self::assertStringNotContainsString('demote', strtolower($option));
        }

        // And the port that supplies the counterparty figure is a binding, so a
        // deployment can swap it without touching this module.
        self::assertInstanceOf(
            RelationTableCounterpartyCounter::class,
            $this->app->make(CounterpartyCounter::class),
        );
    }

    /**
     * The Counterparty module's table, declared here only so the reader has
     * something to read. Kept to the columns this module actually touches.
     */
    private function createRelationTableStandIn(): void
    {
        if (Schema::hasTable('counterparty_relations')) {
            // The real module is deployed here; use its table.
            return;
        }

        $this->createdStandIn = true;

        Schema::create('counterparty_relations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('counterparty_org_id');
            $table->unsignedInteger('total_trade_count')->default(0);
            $table->unique(['organization_id', 'counterparty_org_id'], 'uq_pair');
        });
    }

    private function relation(int $organizationId, int $counterpartyOrgId, int $tradeCount): void
    {
        DB::table('counterparty_relations')->insert([
            'organization_id' => $organizationId,
            'counterparty_org_id' => $counterpartyOrgId,
            'total_trade_count' => $tradeCount,
        ]);
    }
}
