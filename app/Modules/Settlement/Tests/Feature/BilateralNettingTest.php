<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Tests\Feature;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Settlement\Application\Commands\OpenSettlementCommand;
use App\Modules\Settlement\Application\NettingService;
use App\Modules\Settlement\Application\OpenSettlementService;
use App\Modules\Settlement\Domain\Exceptions\SettlementAlreadyNettedException;
use App\Modules\Settlement\Domain\NettingBatchStatus;
use App\Modules\Settlement\Domain\NettingType;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Settlement\Tests\Support\SettlementTestCase;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * F12 through the ledger — bilateral netting, the other half of §5.6.
 *
 * The five-member trading day of §5.6: ten gold transfers between five members
 * collapse to five, one per pair that did not cancel out. Unlike multilateral
 * netting this touches no clearing account at all — each surviving net moves
 * directly between the two members, so nobody takes on anybody else's credit
 * risk. That is why §5.6 confines the multilateral variant to phase 3 and this
 * one is available now.
 */
final class BilateralNettingTest extends SettlementTestCase
{
    private const A = 1;

    private const B = 2;

    private const C = 3;

    private const D = 4;

    private const E = 5;

    private const OPENING_MG = 2_000_000;

    /** §5.6's raw obligations: [trade id, from, to, milligrams]. */
    private const OBLIGATIONS = [
        [2_001, self::A, self::B, 100_000],
        [2_002, self::B, self::A, 70_000],
        [2_003, self::A, self::C, 250_000],
        [2_004, self::C, self::A, 30_000],
        [2_005, self::B, self::C, 40_000],
        [2_006, self::C, self::B, 90_000],
        [2_007, self::A, self::D, 180_000],
        [2_008, self::D, self::A, 200_000],
        [2_009, self::B, self::E, 60_000],
        [2_010, self::E, self::B, 20_000],
    ];

    private const EXPECTED_NET = [
        self::A => -230_000,
        self::B => 40_000,
        self::C => 170_000,
        self::D => -20_000,
        self::E => 40_000,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $orgs = [self::A, self::B, self::C, self::D, self::E];
        $this->setUpLedger(...$orgs);

        foreach ($orgs as $org) {
            $this->depositGold($org, self::OPENING_MG);
        }
    }

    #[Test]
    #[Group('ledger-invariants')]
    public function ten_transfers_become_five_and_every_member_lands_where_gross_would_have_put_them(): void
    {
        $settlements = $this->openTradingDay();
        $netting = $this->app->make(NettingService::class);

        $batch = $netting->propose(
            settlementIds: array_map(static fn (SettlementModel $s): int => (int) $s->id, $settlements),
            type: NettingType::BILATERAL,
            asset: AssetType::GOLD,
        );

        $this->assertSame(5, $batch->participant_count);
        $this->assertSame(10, $batch->gross_transfer_count);
        $this->assertSame(5, $batch->net_transfer_count, '§5.6: ۵ انتقال طلا (۵۰٪ کاهش)');
        $this->assertSame(1_040_000, $batch->gross_volume);
        // 30,000 + 220,000 + 50,000 + 20,000 + 40,000
        $this->assertSame(360_000, $batch->net_volume);
        $this->assertSame(5, $netting->reduction($batch));

        foreach (array_keys(self::EXPECTED_NET) as $org) {
            $netting->accept((int) $batch->id, $org, 9_000 + $org);
        }

        $executed = $netting->execute((int) $batch->id);

        $this->assertSame(NettingBatchStatus::EXECUTED, $executed->status);
        $this->assertGroupSumsToZero((string) $executed->transaction_group);

        // Bilateral netting never touches the clearing account.
        $this->assertSame(0, $this->systemAccountBalance(SystemAccountCode::CLEARING, AssetType::GOLD));

        foreach (self::EXPECTED_NET as $org => $expected) {
            $this->assertSame(
                self::OPENING_MG + $expected,
                $this->goldBalance($org),
                sprintf('Organisation %d must end at opening %+d', $org, $expected),
            );
            $this->assertSame(0, $this->goldBalance($org, Bucket::IN_SETTLEMENT));
        }

        foreach ($settlements as $settlement) {
            $this->assertSame(SettlementStatus::SETTLED, $settlement->fresh()->status);
        }

        $this->assertLedgerConserved();
        $this->assertEveryGroupBalances();
    }

    #[Test]
    public function a_settlement_cannot_be_committed_to_two_batches_at_once(): void
    {
        $settlements = $this->openTradingDay();
        $netting = $this->app->make(NettingService::class);
        $ids = array_map(static fn (SettlementModel $s): int => (int) $s->id, $settlements);

        $netting->propose($ids, NettingType::BILATERAL, AssetType::GOLD);

        $this->expectException(SettlementAlreadyNettedException::class);

        $netting->propose($ids, NettingType::BILATERAL, AssetType::GOLD);
    }

    #[Test]
    public function a_cancelled_batch_puts_its_settlements_back_on_the_gross_path_for_good(): void
    {
        $settlements = $this->openTradingDay();
        $netting = $this->app->make(NettingService::class);
        $ids = array_map(static fn (SettlementModel $s): int => (int) $s->id, $settlements);

        $first = $netting->propose($ids, NettingType::BILATERAL, AssetType::GOLD);
        $netting->cancel((int) $first->id, 'Operator recomputed the day');

        $this->assertSame(NettingBatchStatus::CANCELLED, $first->fresh()->status);

        // §5.6: "ابطال کل دسته ► تسویه ناخالص عادی".
        foreach ($ids as $id) {
            $settlement = SettlementModel::query()->findOrFail($id);
            $this->assertSame(SettlementStatus::PAYMENT_PENDING, $settlement->status);
            $this->assertNull($settlement->netting_batch_id);
        }

        // And that is one-way. §2.4 gives PAYMENT_PENDING no edge back to
        // NETTING_QUEUE, so yesterday's released obligations cannot be quietly
        // swept into a new batch — the members would have to agree again.
        $this->expectException(OperationNotPermittedException::class);

        $netting->propose($ids, NettingType::BILATERAL, AssetType::GOLD);
    }

    /** @return list<SettlementModel> */
    private function openTradingDay(): array
    {
        $service = $this->app->make(OpenSettlementService::class);
        $settlements = [];

        foreach (self::OBLIGATIONS as [$tradeId, $from, $to, $amount]) {
            $this->reserveForTrade($tradeId, $from, $to, $amount, 0);

            $settlements[] = $service->open(OpenSettlementCommand::forTrade(
                tradeId: $tradeId,
                buyerOrganizationId: $to,
                sellerOrganizationId: $from,
                fineWeightMg: $amount,
                cashAmountRial: 0,
            ));
        }

        return $settlements;
    }
}
