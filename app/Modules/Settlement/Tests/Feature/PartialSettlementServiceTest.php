<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Tests\Feature;

use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Settlement\Application\GoldTransferService;
use App\Modules\Settlement\Application\PartialSettlementService;
use App\Modules\Settlement\Application\PaymentService;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Settlement\Tests\Support\SettlementTestCase;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * docs/03-domain/05-settlement.md §5.4 — partial settlement, option A.
 *
 * The document's example: a 500 g / 39.24 bn trade where the buyer pays only
 * 20 bn, about 51 %. Proportionally that buys 254.8 g and the remainder becomes
 * a child settlement.
 *
 *   floor(500,000 mg × 20,000,000,000 / 39,240,000,000) = 254,841 mg
 *
 * 254.841 g — the document's 254.8 g resolved to the milligram. Floor and not
 * round: the buyer gets no more gold than they paid for, and the fraction the
 * division leaves behind stays with the remainder rather than being invented.
 */
final class PartialSettlementServiceTest extends SettlementTestCase
{
    private const SELLER = 184;

    private const BUYER = 291;

    private const FINE_MG = 500_000;

    private const GROSS_RIAL = 39_240_000_000;

    private const BUYER_FEE = 58_860_000;

    private const SELLER_FEE = 39_240_000;

    private const PAID_RIAL = 20_000_000_000;

    private const EXPECTED_DELIVERED_MG = 254_841;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger(self::SELLER, self::BUYER);
        $this->depositGold(self::SELLER, 2_000_000);
        $this->depositRial(self::BUYER, 60_000_000_000);
        $this->depositRial(self::SELLER, 1_000_000_000);
    }

    #[Test]
    public function a_partial_payment_buys_a_proportional_share_of_the_gold(): void
    {
        $settlement = $this->openHalfPaidSettlement();

        $result = $this->app->make(PartialSettlementService::class)->settlePartially(
            settlementId: (int) $settlement->id,
            paidRial: self::PAID_RIAL,
            actorUserId: 7_001,
        );

        $parent = $result['parent'];
        $child = $result['child'];

        // §5.4: 254.8 g moves, the rest stays an open obligation.
        $this->assertSame(self::EXPECTED_DELIVERED_MG, $parent->fine_weight_mg);
        $this->assertSame(self::PAID_RIAL, $parent->cash_amount_rial);
        $this->assertSame(self::FINE_MG - self::EXPECTED_DELIVERED_MG, $child->fine_weight_mg);
        $this->assertSame(self::GROSS_RIAL - self::PAID_RIAL, $child->cash_amount_rial);

        // The child is a settlement in its own right, pointing at its parent.
        $this->assertSame((int) $parent->id, (int) $child->parent_settlement_id);
        $this->assertSame($parent->trade_id, $child->trade_id);
        $this->assertSame(SettlementStatus::PAYMENT_PENDING, $child->status);
        $this->assertStringStartsWith('STL-', (string) $child->settlement_code);
    }

    #[Test]
    public function fees_are_prorated_and_never_created_or_lost(): void
    {
        $settlement = $this->openHalfPaidSettlement();

        $result = $this->app->make(PartialSettlementService::class)->settlePartially(
            (int) $settlement->id,
            self::PAID_RIAL,
        );

        $this->assertSame(
            self::BUYER_FEE,
            $result['parent']->buyer_fee_rial + $result['child']->buyer_fee_rial,
            'Splitting a settlement must not invent or destroy a rial of fee',
        );
        $this->assertSame(
            self::SELLER_FEE,
            $result['parent']->seller_fee_rial + $result['child']->seller_fee_rial,
        );
        $this->assertSame(
            self::FINE_MG,
            $result['parent']->fine_weight_mg + $result['child']->fine_weight_mg,
        );
        $this->assertSame(
            self::GROSS_RIAL,
            $result['parent']->cash_amount_rial + $result['child']->cash_amount_rial,
        );
    }

    #[Test]
    #[Group('ledger-invariants')]
    public function splitting_a_settlement_moves_nothing_in_the_ledger(): void
    {
        $settlement = $this->openHalfPaidSettlement();

        $goldLocked = $this->goldBalance(self::SELLER, Bucket::IN_SETTLEMENT);
        $cashLocked = $this->rialBalance(self::BUYER, Bucket::IN_SETTLEMENT);
        $goldFree = $this->goldBalance(self::SELLER);
        $cashFree = $this->rialBalance(self::BUYER);

        $result = $this->app->make(PartialSettlementService::class)->settlePartially(
            (int) $settlement->id,
            self::PAID_RIAL,
        );

        // Nothing changed organisation or bucket — only which settlement claims
        // the holdings.
        $this->assertSame($goldLocked, $this->goldBalance(self::SELLER, Bucket::IN_SETTLEMENT));
        $this->assertSame($cashLocked, $this->rialBalance(self::BUYER, Bucket::IN_SETTLEMENT));
        $this->assertSame($goldFree, $this->goldBalance(self::SELLER));
        $this->assertSame($cashFree, $this->rialBalance(self::BUYER));

        // And the two rows still account for every locked milligram and rial.
        $this->assertSame(
            $goldLocked,
            $result['parent']->locked_gold_mg + $result['child']->locked_gold_mg,
        );
        $this->assertSame(
            $cashLocked,
            $result['parent']->locked_cash_rial + $result['child']->locked_cash_rial,
        );

        $this->assertLedgerConserved();
    }

    #[Test]
    #[Group('ledger-invariants')]
    public function the_paid_share_settles_and_the_remainder_stays_open(): void
    {
        $settlement = $this->openHalfPaidSettlement();
        $this->makeVaultedLot(self::SELLER, 2_010_050, 9_950, 2_000_000);

        $partial = $this->app->make(PartialSettlementService::class);
        $result = $partial->settlePartially((int) $settlement->id, self::PAID_RIAL);

        $parentId = (int) $result['parent']->id;
        $childId = (int) $result['child']->id;

        $this->app->make(PaymentService::class)->declarePayment($parentId, 7_001, 'PART-1');
        $this->app->make(PaymentService::class)->confirmPayment($parentId, 7_002);
        $this->app->make(GoldTransferService::class)->transfer($parentId, 7_002);

        $this->assertSame(SettlementStatus::SETTLED, SettlementModel::query()->findOrFail($parentId)->status);
        $this->assertSame(
            self::EXPECTED_DELIVERED_MG,
            $this->goldBalance(self::BUYER),
            'The buyer received exactly what they paid for',
        );

        // The remainder is still an obligation, with its assets still locked.
        $child = SettlementModel::query()->findOrFail($childId);
        $this->assertSame(SettlementStatus::PAYMENT_PENDING, $child->status);
        $this->assertSame(
            self::FINE_MG - self::EXPECTED_DELIVERED_MG,
            $this->goldBalance(self::SELLER, Bucket::IN_SETTLEMENT),
        );
        $this->assertSame($child->fine_weight_mg, $child->locked_gold_mg);

        $this->assertLedgerConserved();
        $this->assertEveryGroupBalances();
    }

    #[Test]
    public function paying_everything_or_nothing_is_not_a_partial_settlement(): void
    {
        $settlement = $this->openHalfPaidSettlement();
        $partial = $this->app->make(PartialSettlementService::class);

        try {
            $partial->settlePartially((int) $settlement->id, 0);
            $this->fail('A zero payment is not a partial settlement');
        } catch (OperationNotPermittedException) {
            // expected
        }

        $this->expectException(OperationNotPermittedException::class);
        $partial->settlePartially((int) $settlement->id, self::GROSS_RIAL);
    }

    #[Test]
    public function the_preview_matches_what_the_split_actually_delivers(): void
    {
        $settlement = $this->openHalfPaidSettlement();
        $partial = $this->app->make(PartialSettlementService::class);

        $preview = $partial->preview((int) $settlement->id, self::PAID_RIAL);

        $this->assertSame(self::EXPECTED_DELIVERED_MG, $preview['fine_mg']);
        $this->assertSame(self::PAID_RIAL, $preview['gross_rial']);

        $result = $partial->settlePartially((int) $settlement->id, self::PAID_RIAL);

        $this->assertSame($preview['fine_mg'], $result['parent']->fine_weight_mg);
    }

    #[Test]
    public function merging_a_child_back_restores_the_parent_whole(): void
    {
        $settlement = $this->openHalfPaidSettlement();
        $partial = $this->app->make(PartialSettlementService::class);

        $result = $partial->settlePartially((int) $settlement->id, self::PAID_RIAL);
        $parent = $partial->mergeBack((int) $result['child']->id, 9_001);

        $this->assertSame(self::FINE_MG, $parent->fine_weight_mg);
        $this->assertSame(self::GROSS_RIAL, $parent->cash_amount_rial);
        $this->assertSame(self::BUYER_FEE, $parent->buyer_fee_rial);
        $this->assertSame(self::SELLER_FEE, $parent->seller_fee_rial);
        $this->assertSame(self::FINE_MG, $parent->locked_gold_mg);

        $child = SettlementModel::query()->findOrFail($result['child']->id);
        $this->assertSame(SettlementStatus::CANCELLED, $child->status);
        $this->assertSame(0, $child->locked_gold_mg, 'The child released nothing — the parent holds it');

        $this->assertLedgerConserved();
    }

    private function openHalfPaidSettlement(): SettlementModel
    {
        return $this->openSettlement(
            tradeId: 91_001,
            sellerOrgId: self::SELLER,
            buyerOrgId: self::BUYER,
            fineMg: self::FINE_MG,
            grossRial: self::GROSS_RIAL,
            buyerFee: self::BUYER_FEE,
            sellerFee: self::SELLER_FEE,
        );
    }
}
