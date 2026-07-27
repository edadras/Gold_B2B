<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Tests\Feature;

use App\Modules\Custody\Contracts\GoldLotRepositoryInterface;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Settlement\Application\GoldTransferService;
use App\Modules\Settlement\Application\PaymentService;
use App\Modules\Settlement\Application\SettlementCompletionService;
use App\Modules\Settlement\Domain\DeliveryMethod;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Infrastructure\Models\GoldTransferModel;
use App\Modules\Settlement\Infrastructure\Models\PaymentModel;
use App\Modules\Settlement\Tests\Support\SettlementTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * docs/11-appendix/03-worked-examples.md, example 1 — the settlement half,
 * driven entirely through Settlement's own services.
 *
 * The Ledger module already reproduces this example at the level of raw ledger
 * calls. What this test proves is different and is the point of the module:
 * that declaring a payment, confirming it, and delivering the gold produce
 * exactly the same figures, that the fees land in the platform's account, that
 * nothing is created or destroyed on either side, and that the lot changes
 * owner without moving a centimetre.
 *
 * Every constant below is copied from the document.
 */
final class WorkedExampleOneSettlementTest extends SettlementTestCase
{
    private const SELLER = 184;

    private const BUYER = 291;

    private const TRADE_ID = 88_231;

    // Step 2 of the example: 250 g fine at 78,480,000 rial/g.
    private const FINE_MG = 250_000;

    private const GROSS_RIAL = 19_620_000_000;

    private const BUYER_FEE = 29_430_000;

    private const SELLER_FEE = 19_620_000;

    private const BUYER_NET = 19_649_430_000;      // gross + buyer fee

    private const SELLER_NET = 19_600_380_000;     // gross − seller fee

    // Opening balances.
    private const SELLER_GOLD_OPENING = 1_000_000;

    private const SELLER_RIAL_OPENING = 500_000_000;

    private const BUYER_RIAL_OPENING = 25_000_000_000;

    private const LOT_GROSS_MG = 251_256;

    private const LOT_PURITY = 9_950;

    private const LOT_LOCATION = 'V01-S03-B14';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger(self::SELLER, self::BUYER);
        $this->depositGold(self::SELLER, self::SELLER_GOLD_OPENING);
        $this->depositRial(self::SELLER, self::SELLER_RIAL_OPENING);
        $this->depositRial(self::BUYER, self::BUYER_RIAL_OPENING);
    }

    #[Test]
    #[Group('ledger-invariants')]
    public function the_settlement_reproduces_every_figure_in_worked_example_one(): void
    {
        // GL-00001287: gross 251,256 mg at purity 9950 → fine 250,000 mg.
        $lot = $this->makeVaultedLot(
            ownerOrgId: self::SELLER,
            grossMg: self::LOT_GROSS_MG,
            purityX10: self::LOT_PURITY,
            fineMg: self::FINE_MG,
            location: self::LOT_LOCATION,
        );

        $this->assertSame(self::FINE_MG, (int) $lot->fine_weight_mg);

        // ── step 3: settlement created, both sides locked (g4) ───────────────
        $settlement = $this->openSettlement(
            tradeId: self::TRADE_ID,
            sellerOrgId: self::SELLER,
            buyerOrgId: self::BUYER,
            fineMg: self::FINE_MG,
            grossRial: self::GROSS_RIAL,
            buyerFee: self::BUYER_FEE,
            sellerFee: self::SELLER_FEE,
        );

        $this->assertSame('STL-'.str_pad((string) $settlement->id, 8, '0', STR_PAD_LEFT), $settlement->settlement_code);
        $this->assertSame(SettlementStatus::PAYMENT_PENDING, $settlement->status);
        $this->assertSame(self::FINE_MG, $this->goldBalance(self::SELLER, Bucket::IN_SETTLEMENT));
        $this->assertSame(self::BUYER_NET, $this->rialBalance(self::BUYER, Bucket::IN_SETTLEMENT));
        $this->assertSame(0, $this->goldBalance(self::SELLER, Bucket::RESERVED));
        $this->assertSame(0, $this->rialBalance(self::BUYER, Bucket::RESERVED));

        // ── step 4: the buyer declares payment. No ledger movement at all ────
        $entriesBefore = $this->entryCount();

        $payment = $this->app->make(PaymentService::class)->declarePayment(
            settlementId: (int) $settlement->id,
            actorUserId: 7_001,
            paymentReference: '987654321',
            amountRial: self::BUYER_NET,
        );

        $this->assertSame($entriesBefore, $this->entryCount(), 'Declaring a payment must not touch the ledger');
        $this->assertSame(PaymentModel::DECLARED, $payment->status);
        $this->assertSame('987654321', $settlement->fresh()->payment_reference);
        $this->assertSame(SettlementStatus::PAYMENT_DECLARED, $settlement->fresh()->status);

        // ── step 5: the seller confirms receipt. Rial moves (g5) ─────────────
        $this->app->make(PaymentService::class)->confirmPayment((int) $settlement->id, 7_002);

        $this->assertSame(SettlementStatus::GOLD_TRANSFERRING, $settlement->fresh()->status);
        $this->assertSame(0, $this->rialBalance(self::BUYER, Bucket::IN_SETTLEMENT));
        $this->assertSame(
            self::SELLER_RIAL_OPENING + self::SELLER_NET,
            $this->rialBalance(self::SELLER),
            'Seller receives gross minus their own fee',
        );
        $this->assertSame(
            self::BUYER_FEE + self::SELLER_FEE,
            $this->systemAccountBalance(SystemAccountCode::FEE_INCOME, AssetType::RIAL),
            'Both fees land in the platform fee account',
        );
        $this->assertSame(
            49_050_000,
            $this->systemAccountBalance(SystemAccountCode::FEE_INCOME, AssetType::RIAL),
            'The document quotes SYSTEM/FEE_INCOME = 49,050,000',
        );

        // ── step 6: the gold is delivered (g6) ───────────────────────────────
        $this->app->make(GoldTransferService::class)->transfer((int) $settlement->id, 7_002);

        $settlement->refresh();
        $this->assertSame(SettlementStatus::SETTLED, $settlement->status);

        // ── final state, straight from the document ──────────────────────────
        $this->assertSame(750_000, $this->goldBalance(self::SELLER));
        $this->assertSame(0, $this->goldBalance(self::SELLER, Bucket::RESERVED));
        $this->assertSame(0, $this->goldBalance(self::SELLER, Bucket::IN_SETTLEMENT));
        $this->assertSame(20_100_380_000, $this->rialBalance(self::SELLER));

        $this->assertSame(self::FINE_MG, $this->goldBalance(self::BUYER));
        $this->assertSame(5_350_570_000, $this->rialBalance(self::BUYER));

        // ── the lot: owner changed, nothing physical did ─────────────────────
        $delivered = $this->app->make(GoldLotRepositoryInterface::class)->find((int) $lot->id);

        $this->assertNotNull($delivered);
        $this->assertSame(self::BUYER, $delivered->ownerOrganizationId, 'Ownership moved to the buyer');
        $this->assertSame(CustodianType::VAULT, $delivered->custodianType, 'Custodian type unchanged');
        $this->assertSame((int) $lot->custodian_id, $delivered->custodianId, 'Custodian unchanged');
        $this->assertSame(self::LOT_LOCATION, $delivered->physicalLocation, 'The metal never left its box');
        $this->assertSame(self::FINE_MG, $delivered->fineWeightMg, 'Exact match — no split was needed');

        $transfer = GoldTransferModel::query()->where('settlement_id', $settlement->id)->firstOrFail();
        $this->assertSame(DeliveryMethod::CUSTODY_CHANGE, $transfer->delivery_method);
        $this->assertFalse((bool) $transfer->physical_movement);
        $this->assertFalse((bool) $transfer->split_performed);
        $this->assertTrue($transfer->custodyUnchanged());
        $this->assertSame([(int) $lot->id], $settlement->lotIds());
    }

    #[Test]
    #[Group('ledger-invariants')]
    public function total_gold_and_total_rial_are_conserved(): void
    {
        $this->makeVaultedLot(self::SELLER, self::LOT_GROSS_MG, self::LOT_PURITY, self::FINE_MG);

        $settlement = $this->openSettlement(
            tradeId: self::TRADE_ID,
            sellerOrgId: self::SELLER,
            buyerOrgId: self::BUYER,
            fineMg: self::FINE_MG,
            grossRial: self::GROSS_RIAL,
            buyerFee: self::BUYER_FEE,
            sellerFee: self::SELLER_FEE,
        );

        $this->app->make(PaymentService::class)->declarePayment((int) $settlement->id, 7_001, '987654321');
        $this->app->make(PaymentService::class)->confirmPayment((int) $settlement->id, 7_002);
        $this->app->make(GoldTransferService::class)->transfer((int) $settlement->id, 7_002);

        // "بررسی بقای جرم" — the document's own conservation check.
        $this->assertSame(
            self::SELLER_GOLD_OPENING,
            $this->goldBalance(self::SELLER) + $this->goldBalance(self::BUYER),
            'Gold before: 1,000,000 mg — gold after must match',
        );

        $this->assertSame(
            self::SELLER_RIAL_OPENING + self::BUYER_RIAL_OPENING,
            $this->rialBalance(self::SELLER)
                + $this->rialBalance(self::BUYER)
                + $this->systemAccountBalance(SystemAccountCode::FEE_INCOME, AssetType::RIAL),
            'Rial before: 25,500,000,000 — rial after must match',
        );

        $this->assertLedgerConserved();
        $this->assertEveryGroupBalances();
    }

    /**
     * The same flow with the F5/F8 reference vector of
     * docs/11-appendix/01-formulas.md §1.3–§1.4: 250,000 mg against a cash
     * amount of 19,521,900,000 with fees of 29,282,850 and 19,521,900.
     *
     * Settlement is indifferent to how a price produced an amount — it moves
     * what the trade tells it to — so this is the same assertions over the
     * formulas document's numbers, and it catches any rounding the fee split
     * might introduce.
     */
    #[Test]
    #[Group('ledger-invariants')]
    public function the_formula_reference_vector_settles_and_conserves(): void
    {
        $fineMg = 250_000;
        $gross = 19_521_900_000;
        $buyerFee = 29_282_850;
        $sellerFee = 19_521_900;

        $this->makeVaultedLot(self::SELLER, self::LOT_GROSS_MG, self::LOT_PURITY, $fineMg);

        $settlement = $this->openSettlement(
            tradeId: 88_232,
            sellerOrgId: self::SELLER,
            buyerOrgId: self::BUYER,
            fineMg: $fineMg,
            grossRial: $gross,
            buyerFee: $buyerFee,
            sellerFee: $sellerFee,
        );

        $this->app->make(PaymentService::class)->declarePayment((int) $settlement->id, 7_001, '123456789');
        $this->app->make(PaymentService::class)->confirmPayment((int) $settlement->id, 7_002);
        $this->app->make(GoldTransferService::class)->transfer((int) $settlement->id, 7_002);

        $this->assertSame(SettlementStatus::SETTLED, $settlement->fresh()->status);

        // F9: buyer_net 19,551,182,850 and seller_net 19,502,378,100.
        $this->assertSame($fineMg, $this->goldBalance(self::BUYER));
        $this->assertSame(
            self::SELLER_RIAL_OPENING + ($gross - $sellerFee),
            $this->rialBalance(self::SELLER),
        );
        $this->assertSame(
            self::BUYER_RIAL_OPENING - ($gross + $buyerFee),
            $this->rialBalance(self::BUYER),
        );
        $this->assertSame(
            $buyerFee + $sellerFee,
            $this->systemAccountBalance(SystemAccountCode::FEE_INCOME, AssetType::RIAL),
            'F8 check: 29,282,850 + 19,521,900 = 48,804,750',
        );
        $this->assertSame(
            48_804_750,
            $this->systemAccountBalance(SystemAccountCode::FEE_INCOME, AssetType::RIAL),
        );

        $this->assertSame(
            self::SELLER_GOLD_OPENING,
            $this->goldBalance(self::SELLER) + $this->goldBalance(self::BUYER),
        );
        $this->assertSame(
            self::SELLER_RIAL_OPENING + self::BUYER_RIAL_OPENING,
            $this->rialBalance(self::SELLER)
                + $this->rialBalance(self::BUYER)
                + $this->systemAccountBalance(SystemAccountCode::FEE_INCOME, AssetType::RIAL),
        );

        $this->assertLedgerConserved();
    }

    #[Test]
    public function the_settlement_completes_only_after_the_objection_window(): void
    {
        $this->makeVaultedLot(self::SELLER, self::LOT_GROSS_MG, self::LOT_PURITY, self::FINE_MG);

        $settlement = $this->openSettlement(
            tradeId: self::TRADE_ID,
            sellerOrgId: self::SELLER,
            buyerOrgId: self::BUYER,
            fineMg: self::FINE_MG,
            grossRial: self::GROSS_RIAL,
            buyerFee: self::BUYER_FEE,
            sellerFee: self::SELLER_FEE,
        );

        $this->app->make(PaymentService::class)->declarePayment((int) $settlement->id, 7_001, '987654321');
        $this->app->make(PaymentService::class)->confirmPayment((int) $settlement->id, 7_002);
        $this->app->make(GoldTransferService::class)->transfer((int) $settlement->id, 7_002);

        $completion = $this->app->make(SettlementCompletionService::class);

        $this->assertSame([], $completion->completeDue(now()->toImmutable()));
        $this->assertSame(SettlementStatus::SETTLED, $settlement->fresh()->status);

        $completed = $completion->completeDue(now()->addHours(25)->toImmutable());

        $this->assertSame([(int) $settlement->id], $completed);
        $this->assertSame(SettlementStatus::COMPLETED, $settlement->fresh()->status);
        $this->assertNotNull($settlement->fresh()->completed_at);
    }

    private function entryCount(): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('ledger_entries')->count();
    }
}
