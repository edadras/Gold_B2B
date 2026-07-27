<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Tests;

use App\Modules\Counterparty\Application\ReconciliationService;
use App\Modules\Counterparty\Contracts\RelationAsymmetry;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/** §10.10 — the nightly invariant check and its repair path. */
final class ReconciliationTest extends CounterpartyTestCase
{
    private ReconciliationService $reconciliation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reconciliation = $this->app->make(ReconciliationService::class);
    }

    #[Test]
    public function a_healthy_book_reports_nothing_and_the_command_succeeds(): void
    {
        $this->relations->applyTrade(101, 202, 250_000, -19_522_500_000);
        $this->relations->applyTrade(101, 203, -80_000, 1_000_000);

        self::assertSame([], $this->reconciliation->findings());

        $this->artisan('counterparty:reconcile')
            ->expectsOutputToContain('Counterparty relations reconciled')
            ->assertExitCode(0);
    }

    #[Test]
    public function an_asymmetric_pair_is_reported_once_and_never_auto_repaired(): void
    {
        $this->relations->applyTrade(101, 202, 250_000, 0);

        // Simulate a half-applied write: a movement that reached one side of the
        // pair only. Both that side's balance and its movement log agree, so the
        // drift check stays silent and only symmetry can catch this.
        DB::table('counterparty_movements')->insert([
            'organization_id' => 202,
            'counterparty_org_id' => 101,
            'gold_delta_mg' => 150_000,
            'rial_delta' => 0,
            'kind' => 'TRADE',
            'reference' => 'HALF-APPLIED',
            'occurred_at' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
        ]);

        DB::table('counterparty_relations')
            ->where('organization_id', 202)
            ->where('counterparty_org_id', 101)
            ->update(['gold_balance_mg' => -100_000]);

        $asymmetries = $this->reconciliation->asymmetries();

        self::assertCount(1, $asymmetries, 'one finding per pair, not one per row');
        self::assertSame(RelationAsymmetry::ASYMMETRIC, $asymmetries[0]->kind);
        self::assertSame(150_000, $asymmetries[0]->goldGapMg());
        self::assertSame([], $this->reconciliation->drift(), 'each side agrees with its own movements');

        $this->artisan('counterparty:reconcile --fix')
            ->assertExitCode(1);

        // Still asymmetric: choosing which side is right is a human decision.
        self::assertCount(1, $this->reconciliation->asymmetries());
    }

    #[Test]
    public function an_orphan_relation_is_detected_and_can_be_mirrored(): void
    {
        $this->relations->applyTrade(101, 202, 250_000, -1_000);

        DB::table('counterparty_relations')
            ->where('organization_id', 202)
            ->where('counterparty_org_id', 101)
            ->delete();

        $orphans = $this->reconciliation->orphans();

        self::assertCount(1, $orphans);
        self::assertSame(RelationAsymmetry::ORPHAN, $orphans[0]->kind);

        $this->reconciliation->materialiseMirror(101, 202);

        self::assertSame(-250_000, $this->goldBalance(202, 101));
        self::assertSame(1_000, $this->rialBalance(202, 101));
        self::assertSame([], $this->reconciliation->asymmetries());
        self::assertSame([], $this->reconciliation->orphans());
    }

    #[Test]
    public function drift_between_the_balance_and_its_movements_is_detected_and_repairable(): void
    {
        $this->relations->applyTrade(101, 202, 250_000, 0);

        DB::table('counterparty_relations')
            ->where('organization_id', 101)
            ->where('counterparty_org_id', 202)
            ->update(['gold_balance_mg' => 999_000]);

        $drift = $this->reconciliation->drift();

        self::assertCount(1, $drift);
        self::assertSame(RelationAsymmetry::DRIFT, $drift[0]->kind);
        self::assertSame(749_000, $drift[0]->goldGapMg(), 'stored minus computed');

        $this->artisan('counterparty:reconcile --fix')->assertExitCode(1);

        self::assertSame(250_000, $this->goldBalance(101, 202));
        self::assertSame([], $this->reconciliation->findings());
    }

    #[Test]
    public function the_command_can_emit_machine_readable_findings(): void
    {
        $this->relations->applyTrade(101, 202, 250_000, 0);

        DB::table('counterparty_relations')
            ->where('organization_id', 101)
            ->where('counterparty_org_id', 202)
            ->update(['gold_balance_mg' => 300_000]);

        $this->artisan('counterparty:reconcile --json')
            ->expectsOutputToContain('"kind": "ASYMMETRIC"')
            ->assertExitCode(1);
    }
}
