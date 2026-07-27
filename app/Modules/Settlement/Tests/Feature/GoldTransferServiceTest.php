<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Tests\Feature;

use App\Modules\Custody\Contracts\GoldLotRepositoryInterface;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Settlement\Application\GoldTransferService;
use App\Modules\Settlement\Application\PaymentService;
use App\Modules\Settlement\Domain\DeliveryMethod;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Infrastructure\Models\GoldTransferModel;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Settlement\Tests\Support\SettlementTestCase;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Delivery — §5.3 patterns 3 and 4, and the split of worked example 2.
 *
 * The claim being tested is the one the whole custody layer exists to make:
 * when the metal is in a vault and stays in that vault, delivering it is a
 * change of owner and nothing else. Zero transport cost, zero transport risk,
 * seconds rather than days.
 */
final class GoldTransferServiceTest extends SettlementTestCase
{
    private const SELLER = 184;

    private const BUYER = 291;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger(self::SELLER, self::BUYER);
        $this->depositGold(self::SELLER, 2_000_000);
        $this->depositRial(self::BUYER, 60_000_000_000);
    }

    #[Test]
    #[Group('ledger-invariants')]
    public function a_lot_larger_than_the_trade_is_split_and_only_the_delivered_piece_changes_hands(): void
    {
        // Worked example 2's shape: one 500 g-ish lot, 250 g sold.
        $lot = $this->makeVaultedLot(self::SELLER, 502_513, 9_950, 500_000, 'V01-S03-B14');

        $settlement = $this->settleUpToDelivery(fineMg: 250_000, grossRial: 19_620_000_000);

        $this->app->make(GoldTransferService::class)->transfer((int) $settlement->id, 7_002);

        $settlement->refresh();
        $this->assertSame(SettlementStatus::SETTLED, $settlement->status);

        $transfer = GoldTransferModel::query()->where('settlement_id', $settlement->id)->firstOrFail();
        $this->assertTrue((bool) $transfer->split_performed, 'The lot had to be cut');
        $this->assertFalse((bool) $transfer->physical_movement);
        $this->assertSame(DeliveryMethod::CUSTODY_CHANGE, $transfer->delivery_method);
        $this->assertCount(1, $transfer->lotIds());

        $lots = $this->app->make(GoldLotRepositoryInterface::class);

        // The parent was consumed; its children carry the metal.
        $parent = $lots->find((int) $lot->id);
        $this->assertNotNull($parent);
        $this->assertSame(LotStatus::CONSUMED, $parent->status);

        $delivered = $lots->find($transfer->lotIds()[0]);
        $this->assertNotNull($delivered);
        $this->assertSame(self::BUYER, $delivered->ownerOrganizationId);
        $this->assertSame(250_000, $delivered->fineWeightMg);
        $this->assertSame(CustodianType::VAULT, $delivered->custodianType);
        $this->assertSame('V01-S03-B14', $delivered->physicalLocation, 'The child sits where the parent sat');

        // The seller keeps the remainder, minus the milligram the F1 rounding
        // of the cut cannot preserve.
        $remaining = $lots->totalFineMgForOwner(self::SELLER);
        $this->assertGreaterThanOrEqual(249_998, $remaining);
        $this->assertLessThanOrEqual(250_000, $remaining);

        // The ledger moved exactly what was promised, split or no split.
        $this->assertSame(250_000, $this->goldBalance(self::BUYER));
        $this->assertSame(2_000_000 - 250_000, $this->goldBalance(self::SELLER));
        $this->assertLedgerConserved();
    }

    #[Test]
    public function delivery_is_refused_unless_the_settlement_is_ready_for_it(): void
    {
        $this->makeVaultedLot(self::SELLER, 251_256, 9_950, 250_000);

        $settlement = $this->openSettlement(
            tradeId: 93_001,
            sellerOrgId: self::SELLER,
            buyerOrgId: self::BUYER,
            fineMg: 250_000,
            grossRial: 19_620_000_000,
        );

        // Still PAYMENT_PENDING — nobody has paid.
        $this->expectException(OperationNotPermittedException::class);

        $this->app->make(GoldTransferService::class)->transfer((int) $settlement->id, 7_002);
    }

    #[Test]
    public function the_settlement_records_which_lots_delivered_it(): void
    {
        $first = $this->makeVaultedLot(self::SELLER, 100_503, 9_950, 100_000);
        $second = $this->makeVaultedLot(self::SELLER, 100_503, 9_950, 100_000);

        $settlement = $this->settleUpToDelivery(fineMg: 200_000, grossRial: 15_696_000_000);

        $this->app->make(GoldTransferService::class)->transfer((int) $settlement->id, 7_002);

        $settlement->refresh();

        $this->assertSame([(int) $first->id, (int) $second->id], $settlement->lotIds());
        $this->assertNotNull($settlement->gold_transferred_at);
        $this->assertSame(7_002, (int) $settlement->gold_confirmed_by_user_id);

        $lots = $this->app->make(GoldLotRepositoryInterface::class);

        foreach ($settlement->lotIds() as $lotId) {
            $this->assertTrue($lots->isOwnedBy($lotId, self::BUYER));
        }
    }

    private function settleUpToDelivery(int $fineMg, int $grossRial): SettlementModel
    {
        static $trade = 94_000;
        $trade++;

        $settlement = $this->openSettlement(
            tradeId: $trade,
            sellerOrgId: self::SELLER,
            buyerOrgId: self::BUYER,
            fineMg: $fineMg,
            grossRial: $grossRial,
        );

        $this->app->make(PaymentService::class)->declarePayment((int) $settlement->id, 7_001, 'REF-'.$trade);
        $this->app->make(PaymentService::class)->confirmPayment((int) $settlement->id, 7_002);

        return $settlement->fresh();
    }
}
