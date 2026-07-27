<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Tests\Feature;

use App\Modules\Custody\Contracts\GoldLotRepositoryInterface;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Settlement\Application\GoldTransferService;
use App\Modules\Settlement\Application\PaymentService;
use App\Modules\Settlement\Application\ReversalService;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Infrastructure\Models\SettlementEventModel;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Settlement\Tests\Support\SettlementTestCase;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * docs/03-domain/05-settlement.md §5.8 — correction and reversal.
 *
 * Two rules are under test, and they are the whole of the section:
 *
 *   · dual control — a SETTLEMENT_OFFICER requests, a PLATFORM_ADMIN approves,
 *     and they cannot be the same person;
 *   · "رکورد اصلی Trade و Settlement هرگز حذف یا ویرایش نمی‌شود" — the original
 *     row survives untouched and the ledger gains reversing entries rather
 *     than losing the originals.
 */
final class ReversalServiceTest extends SettlementTestCase
{
    private const SELLER = 184;

    private const BUYER = 291;

    private const FINE_MG = 250_000;

    private const GROSS_RIAL = 19_620_000_000;

    private const BUYER_FEE = 29_430_000;

    private const SELLER_FEE = 19_620_000;

    private const SELLER_GOLD_OPENING = 1_000_000;

    private const SELLER_RIAL_OPENING = 500_000_000;

    private const BUYER_RIAL_OPENING = 25_000_000_000;

    /** The lot settleFully() delivered, so the reversal can check it came back. */
    private int $lotId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger(self::SELLER, self::BUYER);
        $this->depositGold(self::SELLER, self::SELLER_GOLD_OPENING);
        $this->depositRial(self::SELLER, self::SELLER_RIAL_OPENING);
        $this->depositRial(self::BUYER, self::BUYER_RIAL_OPENING);
    }

    #[Test]
    public function a_reversal_needs_two_distinct_users(): void
    {
        $settlement = $this->settleFully();

        $this->expectException(OperationNotPermittedException::class);

        $this->app->make(ReversalService::class)->reverse(
            settlementId: (int) $settlement->id,
            reason: 'Operator error',
            requestedByUserId: 5_150,
            approvedByUserId: 5_150,
        );
    }

    #[Test]
    public function a_reversal_needs_a_reason(): void
    {
        $settlement = $this->settleFully();

        $this->expectException(OperationNotPermittedException::class);

        $this->app->make(ReversalService::class)->reverse(
            settlementId: (int) $settlement->id,
            reason: '   ',
            requestedByUserId: 5_150,
            approvedByUserId: 5_151,
        );
    }

    #[Test]
    public function a_refused_reversal_changes_nothing(): void
    {
        $settlement = $this->settleFully();
        $entriesBefore = DB::table('ledger_entries')->count();

        try {
            $this->app->make(ReversalService::class)->reverse(
                (int) $settlement->id,
                'Operator error',
                5_150,
                5_150,
            );
        } catch (OperationNotPermittedException) {
            // expected
        }

        $this->assertSame(SettlementStatus::SETTLED, $settlement->fresh()->status);
        $this->assertSame($entriesBefore, DB::table('ledger_entries')->count());
    }

    #[Test]
    #[Group('ledger-invariants')]
    public function a_reversal_restores_both_parties_and_leaves_the_original_rows_intact(): void
    {
        $settlement = $this->settleFully();

        $original = SettlementModel::query()->findOrFail($settlement->id)->getAttributes();
        $entriesBefore = DB::table('ledger_entries')->count();
        $eventsBefore = SettlementEventModel::query()->where('settlement_id', $settlement->id)->count();

        $reversed = $this->app->make(ReversalService::class)->reverse(
            settlementId: (int) $settlement->id,
            reason: 'Dispute decided for the buyer — assay was wrong',
            requestedByUserId: 5_150,
            approvedByUserId: 5_151,
        );

        // ── the status moved, the figures did not ────────────────────────────
        $this->assertSame(SettlementStatus::REVERSED, $reversed->status);
        $this->assertNotNull($reversed->reversed_at);
        $this->assertSame(5_150, (int) $reversed->reversal_requested_by_user_id);
        $this->assertSame(5_151, (int) $reversed->reversal_approved_by_user_id);

        foreach ([
            'settlement_code', 'trade_id', 'fine_weight_mg', 'cash_amount_rial',
            'buyer_fee_rial', 'seller_fee_rial', 'gold_deliverer_org_id',
            'gold_receiver_org_id', 'cash_payer_org_id', 'cash_receiver_org_id',
            'allocated_lot_ids', 'settled_at', 'payment_confirmed_at', 'gold_transferred_at',
        ] as $column) {
            $this->assertSame(
                $original[$column],
                $reversed->getAttributes()[$column],
                sprintf('%s must survive a reversal untouched', $column),
            );
        }

        // ── nothing was deleted; entries were added ──────────────────────────
        $this->assertGreaterThan($entriesBefore, DB::table('ledger_entries')->count());
        $this->assertGreaterThan(
            $eventsBefore,
            SettlementEventModel::query()->where('settlement_id', $settlement->id)->count(),
        );
        $this->assertSame(
            6,
            DB::table('ledger_entries')->where('entry_type', EntryType::REVERSAL->value)->count(),
            'Two gold legs, two cash legs and the two fee legs',
        );

        // ── both sides are whole again ───────────────────────────────────────
        $this->assertSame(self::SELLER_GOLD_OPENING, $this->goldBalance(self::SELLER));
        $this->assertSame(0, $this->goldBalance(self::BUYER));
        $this->assertSame(self::SELLER_RIAL_OPENING, $this->rialBalance(self::SELLER));
        $this->assertSame(self::BUYER_RIAL_OPENING, $this->rialBalance(self::BUYER));
        $this->assertSame(
            0,
            $this->systemAccountBalance(SystemAccountCode::FEE_INCOME, AssetType::RIAL),
            'The fees are given back too',
        );

        // ── the lot went home ────────────────────────────────────────────────
        $returned = $this->app->make(GoldLotRepositoryInterface::class)->find($this->lotId);
        $this->assertNotNull($returned);
        $this->assertSame(self::SELLER, $returned->ownerOrganizationId);

        $this->assertLedgerConserved();
        $this->assertEveryGroupBalances();
    }

    #[Test]
    public function a_reversed_settlement_is_terminal(): void
    {
        $settlement = $this->settleFully();

        $this->app->make(ReversalService::class)->reverse(
            (int) $settlement->id,
            'Operator error',
            5_150,
            5_151,
        );

        $this->assertTrue($settlement->fresh()->status->isFinal());

        $this->expectException(OperationNotPermittedException::class);

        $this->app->make(ReversalService::class)->reverse(
            (int) $settlement->id,
            'Again',
            5_150,
            5_151,
        );
    }

    private function settleFully(): SettlementModel
    {
        $lot = $this->makeVaultedLot(self::SELLER, 251_256, 9_950, self::FINE_MG);
        $this->lotId = (int) $lot->id;

        $settlement = $this->openSettlement(
            tradeId: 88_231,
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

        return $settlement->fresh();
    }
}
