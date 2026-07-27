<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Tests;

use App\Modules\Counterparty\Application\ReconciliationService;
use App\Modules\Counterparty\Domain\MovementKind;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The symmetry invariant of §10.2 is the load-bearing property of this module:
 * every other feature (statements, confirmations, concentration) assumes the
 * two sides of a pair are exact negations of each other.
 */
final class RelationSymmetryTest extends CounterpartyTestCase
{
    #[Test]
    public function symmetry_holds_after_five_hundred_random_trades(): void
    {
        // Deterministic "random": a fixed seed keeps a failure reproducible.
        mt_srand(20260110);

        $organizationIds = [11, 12, 13, 14, 15, 16];
        $expected = [];

        for ($i = 0; $i < 500; $i++) {
            $a = $organizationIds[mt_rand(0, count($organizationIds) - 1)];

            do {
                $b = $organizationIds[mt_rand(0, count($organizationIds) - 1)];
            } while ($b === $a);

            $gold = mt_rand(-5_000_000, 5_000_000);
            $rial = mt_rand(-9_000_000, 9_000_000) * 1_000;

            $this->relations->applyTrade($a, $b, $gold, $rial, reference: 'TRD-'.$i);

            // Track the pair once, in its canonical (low, high) orientation: a
            // call made in the other direction lands on the same two rows with
            // the signs flipped.
            [$low, $high, $sign] = $a < $b ? [$a, $b, 1] : [$b, $a, -1];

            $expected[$low][$high] ??= ['gold' => 0, 'rial' => 0];
            $expected[$low][$high]['gold'] += $sign * $gold;
            $expected[$low][$high]['rial'] += $sign * $rial;
        }

        foreach ($expected as $organizationId => $counterparties) {
            foreach ($counterparties as $counterpartyOrgId => $totals) {
                self::assertSame(
                    $totals['gold'],
                    $this->goldBalance($organizationId, $counterpartyOrgId),
                    "gold balance for pair {$organizationId}->{$counterpartyOrgId}",
                );

                self::assertSame(
                    -$totals['gold'],
                    $this->goldBalance($counterpartyOrgId, $organizationId),
                    "mirror gold balance for pair {$counterpartyOrgId}->{$organizationId}",
                );

                self::assertSame(
                    $totals['rial'],
                    $this->rialBalance($organizationId, $counterpartyOrgId),
                );

                self::assertSame(
                    -$totals['rial'],
                    $this->rialBalance($counterpartyOrgId, $organizationId),
                );
            }
        }

        // And the invariant checker agrees there is nothing to report.
        self::assertSame([], $this->app->make(ReconciliationService::class)->findings());
    }

    #[Test]
    public function every_trade_writes_both_directions_even_the_first(): void
    {
        $this->relations->applyTrade(101, 202, 250_000, -19_522_500_000, reference: 'TRD-88201');

        self::assertSame(250_000, $this->goldBalance(101, 202));
        self::assertSame(-250_000, $this->goldBalance(202, 101));
        self::assertSame(-19_522_500_000, $this->rialBalance(101, 202));
        self::assertSame(19_522_500_000, $this->rialBalance(202, 101));

        // Statistics land on both sides too.
        self::assertSame(1, $this->relations->relation(101, 202)?->total_trade_count);
        self::assertSame(1, $this->relations->relation(202, 101)?->total_trade_count);
        self::assertSame(250_000, $this->relations->relation(202, 101)?->total_volume_mg);
    }

    #[Test]
    public function a_member_cannot_hold_a_relation_with_itself(): void
    {
        $this->expectException(OperationNotPermittedException::class);

        $this->relations->applyTrade(101, 101, 1_000, 0);
    }

    #[Test]
    public function adjustments_move_money_without_inflating_the_trade_statistics(): void
    {
        $this->relations->applyTrade(101, 202, 100_000, 0, reference: 'TRD-1');
        $this->relations->applyTrade(
            101,
            202,
            -100_000,
            0,
            reference: 'ADJ-1',
            kind: MovementKind::ADJUSTMENT,
        );

        $relation = $this->relations->relation(101, 202);

        self::assertSame(0, $relation?->gold_balance_mg);
        self::assertSame(1, $relation?->total_trade_count, 'an adjustment is not a trade');
        self::assertSame(100_000, $relation?->total_volume_mg);
        self::assertNotNull($relation?->first_trade_at);
    }

    #[Test]
    public function reconciliation_reports_a_pair_broken_behind_the_service_back(): void
    {
        $this->relations->applyTrade(101, 202, 250_000, 0);

        // Simulate a rogue write that touched one side only.
        DB::table('counterparty_relations')
            ->where('organization_id', 101)
            ->where('counterparty_org_id', 202)
            ->update(['gold_balance_mg' => 999_000]);

        $findings = $this->app->make(ReconciliationService::class)->asymmetries();

        self::assertCount(1, $findings);
        self::assertSame(101, $findings[0]->organizationId);
        self::assertSame(202, $findings[0]->counterpartyOrgId);
        self::assertSame(749_000, $findings[0]->goldGapMg());
    }
}
