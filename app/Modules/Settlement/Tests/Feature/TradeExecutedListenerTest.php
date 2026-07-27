<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Tests\Feature;

use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Settlement\Tests\Support\SettlementTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The seam between Trading and Settlement.
 *
 * Trading is built by another agent and its classes may not exist here at all,
 * so the subscription is by event *name* and the payload is read defensively.
 * These tests fire objects shaped like what Trading is expected to publish —
 * without importing a single Trading class — and check that a settlement comes
 * out the other side with both sides locked.
 *
 * The second half is the more important one: an event that does not carry
 * enough detail must be logged and dropped, not allowed to throw. A settlement
 * that could not be opened automatically is an operational problem; letting the
 * exception escape would roll back Trading's own commit, which is not
 * Settlement's decision to make.
 */
final class TradeExecutedListenerTest extends SettlementTestCase
{
    private const TRADE_EXECUTED = 'App\Modules\Trading\Events\TradeExecuted';

    private const SELLER = 184;

    private const BUYER = 291;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger(self::SELLER, self::BUYER);
        $this->depositGold(self::SELLER, 1_000_000);
        $this->depositRial(self::BUYER, 25_000_000_000);
    }

    #[Test]
    public function a_trade_executed_event_opens_a_settlement_with_both_sides_locked(): void
    {
        $this->reserveForTrade(88_231, self::SELLER, self::BUYER, 250_000, 19_649_430_000);

        event(self::TRADE_EXECUTED, [$this->tradeExecuted()]);

        $settlement = SettlementModel::query()->where('trade_id', 88_231)->firstOrFail();

        $this->assertSame(SettlementStatus::PAYMENT_PENDING, $settlement->status);
        $this->assertSame(self::SELLER, $settlement->gold_deliverer_org_id);
        $this->assertSame(self::BUYER, $settlement->gold_receiver_org_id);
        $this->assertSame(self::BUYER, $settlement->cash_payer_org_id);
        $this->assertSame(self::SELLER, $settlement->cash_receiver_org_id);
        $this->assertSame(250_000, $settlement->fine_weight_mg);
        $this->assertSame(19_620_000_000, $settlement->cash_amount_rial);
        $this->assertSame(29_430_000, $settlement->buyer_fee_rial);
        $this->assertSame(19_620_000, $settlement->seller_fee_rial);

        $this->assertSame(250_000, $this->goldBalance(self::SELLER, Bucket::IN_SETTLEMENT));
        $this->assertSame(19_649_430_000, $this->rialBalance(self::BUYER, Bucket::IN_SETTLEMENT));
    }

    #[Test]
    public function a_duplicate_delivery_does_not_lock_the_assets_twice(): void
    {
        $this->reserveForTrade(88_231, self::SELLER, self::BUYER, 250_000, 19_649_430_000);

        event(self::TRADE_EXECUTED, [$this->tradeExecuted()]);
        event(self::TRADE_EXECUTED, [$this->tradeExecuted()]);
        event(self::TRADE_EXECUTED, [$this->tradeExecuted()]);

        $this->assertSame(1, SettlementModel::query()->where('trade_id', 88_231)->count());
        $this->assertSame(250_000, $this->goldBalance(self::SELLER, Bucket::IN_SETTLEMENT));
        $this->assertLedgerConserved();
    }

    #[Test]
    public function snake_case_property_names_are_understood_too(): void
    {
        $this->reserveForTrade(77_001, self::SELLER, self::BUYER, 100_000, 7_800_000_000);

        event(self::TRADE_EXECUTED, [new class
        {
            public int $trade_id = 77_001;

            public int $buyer_organization_id = 291;

            public int $seller_organization_id = 184;

            public int $quantity_fine_mg = 100_000;

            public int $gross_amount_rial = 7_800_000_000;
        }]);

        $this->assertSame(1, SettlementModel::query()->where('trade_id', 77_001)->count());
    }

    #[Test]
    public function an_event_without_enough_detail_is_dropped_rather_than_thrown(): void
    {
        event(self::TRADE_EXECUTED, [new class
        {
            public int $tradeId = 55_555;
        }]);

        $this->assertSame(0, SettlementModel::query()->count());
    }

    #[Test]
    public function a_trade_whose_assets_were_never_reserved_does_not_break_the_dispatcher(): void
    {
        // No reserve() call, so ASSETS_LOCKED cannot succeed. The listener must
        // swallow that rather than propagate it into Trading's transaction.
        event(self::TRADE_EXECUTED, [$this->tradeExecuted()]);

        $settlement = SettlementModel::query()->where('trade_id', 88_231)->first();

        // The row may exist in CREATED — the lock failed, not the insert — but
        // nothing was locked and no exception escaped.
        $this->assertSame(0, $this->goldBalance(self::SELLER, Bucket::IN_SETTLEMENT));

        if ($settlement instanceof SettlementModel) {
            $this->assertSame(SettlementStatus::CREATED, $settlement->status);
        }

        $this->assertLedgerConserved();
    }

    /** An object shaped like Trading's TradeExecuted, built without importing it. */
    private function tradeExecuted(): object
    {
        return new class
        {
            public int $tradeId = 88_231;

            public string $tradeCode = 'TRD-00088231';

            public int $buyerOrganizationId = 291;

            public int $sellerOrganizationId = 184;

            public int $quantityFineMg = 250_000;

            public int $pricePerGramRial = 78_480_000;

            public int $grossAmountRial = 19_620_000_000;

            public int $buyerFeeRial = 29_430_000;

            public int $sellerFeeRial = 19_620_000;

            public string $settlementType = 'T0';

            public string $settlementDeadline = '2026-07-27T17:00:00Z';
        };
    }
}
