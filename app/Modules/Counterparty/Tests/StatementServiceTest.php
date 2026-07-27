<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Tests;

use App\Modules\Counterparty\Application\StatementService;
use App\Modules\Counterparty\Domain\MovementKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Statement arithmetic and, above all, the reconciliation check that §15.7
 * makes mandatory: a flow report that cannot prove its closing balance against
 * an independently maintained figure is not a report, it is a guess.
 */
final class StatementServiceTest extends CounterpartyTestCase
{
    private StatementService $statements;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statements = $this->app->make(StatementService::class);
    }

    #[Test]
    public function closing_balance_ties_to_the_stored_relation_balance(): void
    {
        // The worked example from §10.3.
        $this->relations->applyTrade(101, 202, 250_000, -19_522_500_000,
            reference: 'TRD-88201', occurredAt: Carbon::parse('2026-01-03 10:00:00'));
        $this->relations->applyTrade(101, 202, 0, 19_522_500_000,
            reference: 'STL-1', kind: MovementKind::SETTLEMENT,
            occurredAt: Carbon::parse('2026-01-05 10:00:00'));
        $this->relations->applyTrade(101, 202, -100_000, 7_848_000_000,
            reference: 'TRD-88290', occurredAt: Carbon::parse('2026-01-07 10:00:00'));
        $this->relations->applyTrade(101, 202, -150_000, 0,
            reference: 'STL-2', kind: MovementKind::SETTLEMENT,
            occurredAt: Carbon::parse('2026-01-09 10:00:00'));
        $this->relations->applyTrade(101, 202, 0, -7_848_000_000,
            reference: 'STL-3', kind: MovementKind::SETTLEMENT,
            occurredAt: Carbon::parse('2026-01-12 10:00:00'));

        $statement = $this->statements->build(101, 202, '2026-01-01', '2026-01-31');

        self::assertSame(0, $statement->openingGoldMg);
        self::assertSame(0, $statement->openingRial);
        self::assertSame(5, $statement->movementCount());
        self::assertSame(0, $statement->closingGoldMg);
        self::assertSame(0, $statement->closingRial);
        self::assertSame($statement->storedGoldMg, $statement->closingGoldMg);
        self::assertSame($statement->storedRial, $statement->closingRial);
        self::assertTrue($statement->isReconciled);
        self::assertSame(['gold_mg' => 0, 'rial' => 0], $statement->reconciliationGap());

        // The running balance follows the doc's column: 250 g after the buy,
        // still 250 g after the rial settlement, 150 g after the sale.
        self::assertSame(250_000, $statement->lines[0]->runningGoldMg);
        self::assertSame(250_000, $statement->lines[1]->runningGoldMg);
        self::assertSame(150_000, $statement->lines[2]->runningGoldMg);
        self::assertSame(0, $statement->lines[3]->runningGoldMg);
    }

    #[Test]
    public function opening_balance_carries_everything_before_the_period(): void
    {
        $this->relations->applyTrade(101, 202, 400_000, -1_000, occurredAt: Carbon::parse('2025-12-20 09:00:00'));
        $this->relations->applyTrade(101, 202, 50_000, 7_000, occurredAt: Carbon::parse('2026-01-04 09:00:00'));

        $statement = $this->statements->build(101, 202, '2026-01-01', '2026-01-31');

        self::assertSame(400_000, $statement->openingGoldMg);
        self::assertSame(-1_000, $statement->openingRial);
        self::assertSame(1, $statement->movementCount());
        self::assertSame(450_000, $statement->closingGoldMg);
        self::assertSame(6_000, $statement->closingRial);
        self::assertTrue($statement->isReconciled);
    }

    #[Test]
    public function a_historical_window_reconciles_against_the_full_history_instead(): void
    {
        $this->relations->applyTrade(101, 202, 100_000, 0, occurredAt: Carbon::parse('2026-01-05 09:00:00'));
        $this->relations->applyTrade(101, 202, 70_000, 0, occurredAt: Carbon::parse('2026-02-05 09:00:00'));

        $january = $this->statements->build(101, 202, '2026-01-01', '2026-01-31');

        self::assertTrue($january->hasMovementsAfterPeriod);
        self::assertSame(100_000, $january->closingGoldMg);
        self::assertSame(170_000, $january->storedGoldMg);
        // Different by design, and still reconciled: the movement log as a whole
        // agrees with the stored balance.
        self::assertTrue($january->isReconciled);
        self::assertSame(['gold_mg' => 0, 'rial' => 0], $january->reconciliationGap());
    }

    #[Test]
    public function a_balance_edited_outside_the_movement_log_fails_reconciliation(): void
    {
        $this->relations->applyTrade(101, 202, 100_000, 0, occurredAt: Carbon::parse('2026-01-05 09:00:00'));

        DB::table('counterparty_relations')
            ->where('organization_id', 101)
            ->where('counterparty_org_id', 202)
            ->update(['gold_balance_mg' => 123_456]);

        $statement = $this->statements->build(101, 202, '2026-01-01', '2026-01-31');

        self::assertFalse($statement->isReconciled);
        self::assertSame(100_000 - 123_456, $statement->reconciliationGap()['gold_mg']);
    }

    #[Test]
    public function the_mirror_statement_is_the_exact_negation(): void
    {
        $this->relations->applyTrade(101, 202, 250_000, -19_522_500_000,
            occurredAt: Carbon::parse('2026-01-03 10:00:00'));

        $ours = $this->statements->build(101, 202, '2026-01-01', '2026-01-31');
        $theirs = $this->statements->build(202, 101, '2026-01-01', '2026-01-31');

        self::assertSame($ours->closingGoldMg, -$theirs->closingGoldMg);
        self::assertSame($ours->closingRial, -$theirs->closingRial);
        self::assertTrue($theirs->isReconciled);
    }
}
