<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Tests\Feature;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Settlement\Application\NettingService;
use App\Modules\Settlement\Domain\NettingBatchStatus;
use App\Modules\Settlement\Domain\NettingType;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Infrastructure\Models\NettingPositionModel;
use App\Modules\Settlement\Infrastructure\Models\NettingSettlementModel;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Settlement\Tests\Support\SettlementTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * docs/11-appendix/03-worked-examples.md, example 3 — end-of-day netting.
 *
 * Six gold obligations between three members:
 *
 *   STL-1001  A → B  100,000 mg     STL-1002  B → A   70,000 mg
 *   STL-1003  A → C  250,000 mg     STL-1004  C → A   30,000 mg
 *   STL-1005  B → C   40,000 mg     STL-1006  C → B   90,000 mg
 *
 * F13 collapses them to A −250,000 / B +80,000 / C +170,000, which sums to
 * zero, and six transfers become three. This test asserts every one of those
 * numbers, that the CLEARING account is left at exactly zero, and — invariant
 * N4, the most important of the six — that each member ends up with precisely
 * what gross settlement would have given them.
 */
final class WorkedExampleThreeNettingTest extends SettlementTestCase
{
    private const A = 101;

    private const B = 102;

    private const C = 103;

    /** Opening gold, generous enough that nothing is constrained by balance. */
    private const OPENING_MG = 1_000_000;

    /** The six obligations of the document, in its own order. */
    private const OBLIGATIONS = [
        [1_001, self::A, self::B, 100_000],
        [1_002, self::B, self::A, 70_000],
        [1_003, self::A, self::C, 250_000],
        [1_004, self::C, self::A, 30_000],
        [1_005, self::B, self::C, 40_000],
        [1_006, self::C, self::B, 90_000],
    ];

    private const EXPECTED_NET = [
        self::A => -250_000,
        self::B => 80_000,
        self::C => 170_000,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger(self::A, self::B, self::C);

        foreach ([self::A, self::B, self::C] as $org) {
            $this->depositGold($org, self::OPENING_MG);
        }
    }

    #[Test]
    #[Group('ledger-invariants')]
    public function six_obligations_net_to_three_positions_that_sum_to_zero(): void
    {
        $settlements = $this->openSixObligations();

        $batch = $this->app->make(NettingService::class)->propose(
            settlementIds: array_map(static fn (SettlementModel $s): int => (int) $s->id, $settlements),
            type: NettingType::MULTILATERAL,
            asset: AssetType::GOLD,
        );

        // ── the batch record, field for field ────────────────────────────────
        $this->assertSame(NettingBatchStatus::PROPOSED, $batch->status);
        $this->assertSame(NettingType::MULTILATERAL, $batch->netting_type);
        $this->assertSame(AssetType::GOLD, $batch->asset_type);
        $this->assertSame(3, $batch->participant_count);
        $this->assertSame(6, $batch->gross_transfer_count);
        $this->assertSame(3, $batch->net_transfer_count, 'Six transfers collapse to three');
        $this->assertSame(580_000, $batch->gross_volume);
        $this->assertSame(250_000, $batch->net_volume);

        // ── the three net positions ──────────────────────────────────────────
        $positions = NettingPositionModel::query()
            ->where('batch_id', $batch->id)
            ->orderBy('organization_id')
            ->get()
            ->keyBy('organization_id');

        $this->assertSame(100_000, (int) $positions[self::A]->gross_in);
        $this->assertSame(350_000, (int) $positions[self::A]->gross_out);
        $this->assertSame(-250_000, (int) $positions[self::A]->net_position);

        $this->assertSame(190_000, (int) $positions[self::B]->gross_in);
        $this->assertSame(110_000, (int) $positions[self::B]->gross_out);
        $this->assertSame(80_000, (int) $positions[self::B]->net_position);

        $this->assertSame(290_000, (int) $positions[self::C]->gross_in);
        $this->assertSame(120_000, (int) $positions[self::C]->gross_out);
        $this->assertSame(170_000, (int) $positions[self::C]->net_position);

        // Invariant N1: −250,000 + 80,000 + 170,000 = 0.
        $this->assertSame(
            0,
            (int) NettingPositionModel::query()->where('batch_id', $batch->id)->sum('net_position'),
            'Σ net_position must be exactly zero',
        );

        // All six obligations are attached to the batch.
        $this->assertSame(
            6,
            NettingSettlementModel::query()->where('batch_id', $batch->id)->count(),
        );

        foreach ($settlements as $settlement) {
            $this->assertSame(SettlementStatus::NETTING_QUEUE, $settlement->fresh()->status);
        }
    }

    #[Test]
    #[Group('ledger-invariants')]
    public function execution_leaves_clearing_at_zero_and_matches_gross_settlement(): void
    {
        $settlements = $this->openSixObligations();
        $netting = $this->app->make(NettingService::class);

        $batch = $netting->propose(
            settlementIds: array_map(static fn (SettlementModel $s): int => (int) $s->id, $settlements),
            type: NettingType::MULTILATERAL,
            asset: AssetType::GOLD,
        );

        // Every participant must accept — ADR-009, invariant N6.
        foreach ([self::A, self::B, self::C] as $org) {
            $netting->accept((int) $batch->id, $org, 9_000 + $org);
        }

        $this->assertSame([], $netting->pendingParticipants((int) $batch->id));

        $executed = $netting->execute((int) $batch->id);

        $this->assertSame(NettingBatchStatus::EXECUTED, $executed->status);
        $this->assertNotNull($executed->transaction_group);

        // ── one balanced transaction group for the whole batch ───────────────
        $this->assertGroupSumsToZero((string) $executed->transaction_group);

        // ── the clearing account is left at zero ─────────────────────────────
        $this->assertSame(
            0,
            $netting->clearingBalance(AssetType::GOLD),
            'SYSTEM/CLEARING must be exactly zero after a multilateral batch',
        );
        $this->assertSame(
            0,
            $this->systemAccountBalance(SystemAccountCode::CLEARING, AssetType::GOLD),
        );

        // ── each member's position equals gross settlement ───────────────────
        foreach (self::EXPECTED_NET as $org => $expected) {
            $this->assertSame(
                self::OPENING_MG + $expected,
                $this->goldBalance($org),
                sprintf('Organisation %d must end at opening %+d', $org, $expected),
            );

            $this->assertSame(
                $expected,
                $this->grossOutcomeFor($org),
                'The document computes the same change by gross settlement',
            );

            $this->assertSame(0, $this->goldBalance($org, Bucket::IN_SETTLEMENT));
        }

        // ── all six settlements are settled ──────────────────────────────────
        foreach ($settlements as $settlement) {
            $fresh = $settlement->fresh();
            $this->assertSame(SettlementStatus::SETTLED, $fresh->status);
            $this->assertSame((int) $batch->id, (int) $fresh->netting_batch_id);
            $this->assertNotNull($fresh->settled_at);
        }

        $this->assertLedgerConserved();
        $this->assertEveryGroupBalances();
    }

    #[Test]
    public function a_single_rejection_cancels_the_batch_and_settlements_fall_back_to_gross(): void
    {
        $settlements = $this->openSixObligations();
        $netting = $this->app->make(NettingService::class);

        $batch = $netting->propose(
            settlementIds: array_map(static fn (SettlementModel $s): int => (int) $s->id, $settlements),
            type: NettingType::MULTILATERAL,
            asset: AssetType::GOLD,
        );

        $netting->accept((int) $batch->id, self::A, 9_101);
        $netting->accept((int) $batch->id, self::B, 9_102);

        $goldBefore = [
            self::A => $this->goldBalance(self::A),
            self::B => $this->goldBalance(self::B),
            self::C => $this->goldBalance(self::C),
        ];

        $netting->reject((int) $batch->id, self::C, 'Disputed weight on STL-1006', 9_103);

        $batch->refresh();
        $this->assertSame(NettingBatchStatus::CANCELLED, $batch->status);
        $this->assertNotNull($batch->cancelled_reason);
        $this->assertNull($batch->transaction_group, 'A cancelled batch writes nothing to the ledger');

        // Not a single milligram moved.
        foreach ($goldBefore as $org => $balance) {
            $this->assertSame($balance, $this->goldBalance($org), 'A rejected batch must move nothing');
        }

        // Every settlement is back on the ordinary gross path.
        foreach ($settlements as $settlement) {
            $fresh = $settlement->fresh();
            $this->assertSame(SettlementStatus::PAYMENT_PENDING, $fresh->status);
            $this->assertNull($fresh->netting_batch_id);
            $this->assertSame($fresh->fine_weight_mg, $fresh->locked_gold_mg, 'Assets stay locked');
        }
    }

    #[Test]
    public function a_batch_cannot_be_executed_while_anybody_has_not_accepted(): void
    {
        $settlements = $this->openSixObligations();
        $netting = $this->app->make(NettingService::class);

        $batch = $netting->propose(
            settlementIds: array_map(static fn (SettlementModel $s): int => (int) $s->id, $settlements),
            type: NettingType::MULTILATERAL,
            asset: AssetType::GOLD,
        );

        $netting->accept((int) $batch->id, self::A, 9_101);
        $netting->accept((int) $batch->id, self::B, 9_102);

        $this->assertSame([self::C], $netting->pendingParticipants((int) $batch->id));

        $this->expectException(\App\Modules\Settlement\Domain\Exceptions\NettingNotAcceptedException::class);

        $netting->execute((int) $batch->id);
    }

    /**
     * The six settlements, each with its assets locked and queued for netting.
     *
     * Cash is left at zero: example 3 nets gold only, and a gold-only batch
     * discharges the delivery leg. Loading a cash amount as well would lock
     * rial that this example never settles.
     *
     * @return list<SettlementModel>
     */
    private function openSixObligations(): array
    {
        $settlements = [];

        foreach (self::OBLIGATIONS as [$tradeId, $from, $to, $amount]) {
            $this->reserveForTrade($tradeId, $from, $to, $amount, 0);

            $settlements[] = $this->app->make(
                \App\Modules\Settlement\Application\OpenSettlementService::class
            )->open(
                \App\Modules\Settlement\Application\Commands\OpenSettlementCommand::forTrade(
                    tradeId: $tradeId,
                    buyerOrganizationId: $to,
                    sellerOrganizationId: $from,
                    fineWeightMg: $amount,
                    cashAmountRial: 0,
                )
            );
        }

        return $settlements;
    }

    /**
     * What gross settlement would have done to this organisation, recomputed
     * straight from the obligation list — the document's own cross-check.
     */
    private function grossOutcomeFor(int $organizationId): int
    {
        $change = 0;

        foreach (self::OBLIGATIONS as [, $from, $to, $amount]) {
            if ($from === $organizationId) {
                $change -= $amount;
            }

            if ($to === $organizationId) {
                $change += $amount;
            }
        }

        return $change;
    }
}
