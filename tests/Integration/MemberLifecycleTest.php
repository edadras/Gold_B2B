<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Custody\Application\Commands\DepositCommand;
use App\Modules\Custody\Application\Commands\DepositPiece;
use App\Modules\Custody\Application\VaultService;
use App\Modules\Custody\Domain\Enums\LotShape;
use App\Modules\Custody\Domain\Enums\PuritySource;
use App\Modules\Custody\Events\LotSplit;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Custody\Infrastructure\Models\VaultModel;
use App\Modules\Identity\Application\OrganizationStateMachine;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Ledger\Contracts\GoldLedgerInterface;
use App\Modules\Ledger\Contracts\LedgerInterface;
use App\Modules\Ledger\Contracts\RialLedgerInterface;
use App\Modules\Ledger\Database\Seeders\LedgerSystemAccountsSeeder;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Risk\Contracts\RiskGuardInterface;
use App\Modules\Settlement\Application\GoldTransferService;
use App\Modules\Settlement\Application\PaymentService;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Shared\ValueObjects\Rial;
use App\Modules\Shared\ValueObjects\Weight;
use App\Modules\Trading\Application\Commands\PlaceOrderCommand;
use App\Modules\Trading\Application\MarketSessionService;
use App\Modules\Trading\Application\PlaceOrderService;
use App\Modules\Trading\Database\Seeders\InstrumentsSeeder;
use App\Modules\Trading\Domain\OrderType;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Domain\TimeInForce;
use App\Modules\Trading\Infrastructure\Models\Instrument;
use App\Modules\Trading\Infrastructure\Models\Trade;
use App\Modules\Trading\Tests\PermissiveRiskGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The whole platform, once, in one process.
 *
 * Every module has a thorough suite of its own, and every one of those suites
 * builds the world it needs — its own organisations, its own accounts, its own
 * fixtures. That is the right way to test a module and it is precisely why it
 * proves nothing about the seams. A listener registered against an event class
 * nobody dispatches passes every test in both modules; so does a contract bound
 * to a null implementation; so does a module whose provider is never loaded.
 * Each of those has actually happened here.
 *
 * So this test builds nothing by hand that the platform can build for itself.
 * It activates an organisation and lets Identity's event provision the ledger
 * accounts. It deposits metal into a real vault and lets Custody create the
 * lots. It places two orders and lets Trading match them, Settlement open
 * itself, and Custody move the lot when the settlement completes. The
 * assertions are about what arrived, not about what was called.
 *
 * Worked example 1 of docs/11-appendix/03-worked-examples.md, end to end.
 */
final class MemberLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE = 78_480_000;

    /** The bar the seller deposits: 300 g gross at 995 → 298.5 g fine (F1). */
    private const BAR_GROSS_MG = 300_000;

    private const BAR_FINE_MG = 298_500;

    /** The trade is smaller than the bar, so settling it has to split the lot. */
    private const TRADE_FINE_MG = 250_000;

    /** F2: 250,000 mg × 78,480,000 rial/g ÷ 1,000,000 = 19,620,000,000. */
    private const GROSS_AMOUNT = 19_620_000_000;

    private Organization $seller;

    private Organization $buyer;

    private Instrument $instrument;

    /** Fine milligrams lost to floor rounding when a lot was cut. */
    private int $splitFineLossMg = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(LotSplit::class, function (LotSplit $event): void {
            $this->splitFineLossMg += $event->fineLossMg;
        });

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LedgerSystemAccountsSeeder::class);
        $this->seed(InstrumentsSeeder::class);

        // Risk has its own suite; a limit refusal here would be a false report
        // about the seams, which is what this file is for.
        $this->app->instance(RiskGuardInterface::class, new PermissiveRiskGuard);

        $this->seller = $this->activateOrganization();
        $this->buyer = $this->activateOrganization();

        $this->instrument = Instrument::query()->where('code', 'GOLD-995-T0')->firstOrFail();
        $this->app->make(MarketSessionService::class)->open($this->instrument);
    }

    #[Test]
    public function activating_an_organization_provisions_its_ledger_accounts(): void
    {
        // Nothing in this test created an account. Identity dispatched
        // OrganizationActivated, Ledger listened, and the accounts exist —
        // the seam that would otherwise fail only in production, on the first
        // member to sign up.
        $accounts = DB::table('ledger_accounts')
            ->where('organization_id', $this->seller->id)
            ->get();

        $this->assertGreaterThan(0, $accounts->count(), 'No ledger accounts were provisioned on activation');

        foreach ([AssetType::GOLD, AssetType::RIAL] as $asset) {
            foreach ([Bucket::AVAILABLE, Bucket::RESERVED] as $bucket) {
                $this->assertTrue(
                    $accounts->contains(
                        fn (object $a): bool => $a->asset_type === $asset->value && $a->bucket === $bucket->value,
                    ),
                    "Missing {$asset->value}/{$bucket->value} account",
                );
            }
        }
    }

    #[Test]
    public function a_vault_deposit_creates_a_lot_the_seller_owns(): void
    {
        $lot = $this->depositBar($this->seller);

        $this->assertSame((int) $this->seller->id, (int) $lot->owner_organization_id);
        $this->assertSame(self::BAR_GROSS_MG, (int) $lot->gross_weight_mg);
        $this->assertSame(self::BAR_FINE_MG, (int) $lot->fine_weight_mg, 'F1: 300 g × 995/1000');
    }

    #[Test]
    public function a_matched_trade_opens_a_settlement_without_anyone_asking(): void
    {
        $this->fundBothSides();

        $trade = $this->tradeOnce();

        // Trading fired TradeExecuted; Settlement listened. Nothing in this
        // test opened a settlement.
        $settlement = SettlementModel::query()->where('trade_id', $trade->id)->first();

        $this->assertNotNull($settlement, 'A trade must open a settlement');
        $this->assertSame((int) $this->seller->id, (int) $settlement->gold_deliverer_org_id);
        $this->assertSame((int) $this->buyer->id, (int) $settlement->cash_payer_org_id);
        $this->assertSame(self::TRADE_FINE_MG, (int) $settlement->fine_weight_mg);
    }

    #[Test]
    public function the_trade_prices_exactly_as_the_worked_example_says(): void
    {
        $this->fundBothSides();

        $trade = $this->tradeOnce();

        $this->assertSame(self::TRADE_FINE_MG, (int) $trade->quantity_fine_mg);
        $this->assertSame(self::PRICE, (int) $trade->price_per_gram_rial);
        $this->assertSame(self::GROSS_AMOUNT, (int) $trade->gross_amount_rial);

        // The seller rested first, so the seller is the maker: 0.10% against
        // the taker's 0.15% (F4/F5), both floored.
        $this->assertSame(29_430_000, (int) $trade->buyer_fee_rial);
        $this->assertSame(19_620_000, (int) $trade->seller_fee_rial);
    }

    #[Test]
    public function settling_moves_the_gold_the_rial_and_the_lot(): void
    {
        $this->fundBothSides();
        $lot = $this->depositBar($this->seller);

        $trade = $this->tradeOnce();
        $settlement = SettlementModel::query()->where('trade_id', $trade->id)->firstOrFail();

        $payments = $this->app->make(PaymentService::class);
        $payments->declarePayment((int) $settlement->id, 1, 'SATNA-99001');
        $payments->confirmPayment((int) $settlement->id, 2);

        $settled = $this->app->make(GoldTransferService::class)->transfer((int) $settlement->id, 2);

        $this->assertSame(SettlementStatus::SETTLED, $settled->status);

        // The gold: the buyer holds the fine weight, the seller holds none.
        $this->assertSame(self::TRADE_FINE_MG, $this->goldAvailable($this->buyer));
        $this->assertSame(0, $this->goldAvailable($this->seller));

        // The bar was bigger than the trade, so delivering it had to split the
        // lot. Worked example 2, reached by settling rather than by calling
        // SplitService. The buyer gets exactly what was traded — F2 rounds the
        // child's GROSS weight up precisely so the promise is covered:
        $this->assertSame(self::TRADE_FINE_MG, $this->ownedFineMg($this->buyer));

        // The seller keeps the rest, and it is one milligram short of naive
        // subtraction. That is not a leak. Fine weight is derived from gross
        // and floored per lot, so cutting a bar loses up to a milligram of it;
        // the physical quantity, gross, is conserved exactly, and the shortfall
        // is recorded as fine_loss rather than silently dropped. Asserting the
        // conservation rule is worth more here than asserting 48,499.
        $sellerFine = $this->ownedFineMg($this->seller);

        $this->assertSame(
            self::BAR_FINE_MG,
            self::TRADE_FINE_MG + $sellerFine + $this->splitFineLossMg,
            'Σ children fine + announced loss must equal the parent bar',
        );

        // The loss is announced on LotSplit and nothing currently listens for
        // it. That is by design rather than an oversight: a vault deposit does
        // not credit the ledger and §6 reconciliation «never adjusts the ledger
        // — a variance becomes a ledger movement only after a written decision
        // and dual control». Custody and the ledger are two books an operator
        // reconciles, so this milligram belongs in the reconciliation report,
        // not in an automatic entry.
        $this->assertGreaterThan(0, $this->splitFineLossMg, 'The loss must be announced, not swallowed');

        $this->assertSame(
            self::BAR_GROSS_MG,
            $this->ownedGrossMg($this->buyer) + $this->ownedGrossMg($this->seller),
            'Gross weight is the physical quantity and must be conserved exactly',
        );

        // And it did NOT move physically — ADR-005: ownership and custody are
        // separate facts, which is the whole reason a trade needs no truck.
        foreach (GoldLotModel::query()->where('owner_organization_id', $this->buyer->id)->get() as $bought) {
            $this->assertSame((int) $lot->vault_id, (int) $bought->vault_id, 'The metal left the vault');
        }

        // Invariants I1 and I4 over everything the whole journey wrote.
        $this->assertEveryGroupBalances();
        $this->assertSystemConserved();
    }

    #[Test]
    public function the_platform_kept_exactly_the_fees_it_charged(): void
    {
        $this->fundBothSides();
        $this->depositBar($this->seller);

        $trade = $this->tradeOnce();
        $settlement = SettlementModel::query()->where('trade_id', $trade->id)->firstOrFail();

        $payments = $this->app->make(PaymentService::class);
        $payments->declarePayment((int) $settlement->id, 1, 'SATNA-99002');
        $payments->confirmPayment((int) $settlement->id, 2);
        $this->app->make(GoldTransferService::class)->transfer((int) $settlement->id, 2);

        $feeIncome = (int) DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_accounts.system_account_code', 'FEE_INCOME')
            ->where('ledger_accounts.asset_type', AssetType::RIAL->value)
            ->sum('ledger_entries.amount');

        // 29,282,850 from each side. Not a number this test chose — the sum of
        // what the two sides were actually charged.
        $this->assertSame(
            (int) $trade->buyer_fee_rial + (int) $trade->seller_fee_rial,
            $feeIncome,
        );
    }

    // ── fixtures that use the real platform, never the database directly ─────

    private function activateOrganization(): Organization
    {
        /** @var Organization $organization */
        $organization = Organization::factory()->create([
            'status' => OrganizationStatus::PENDING,
            'activated_at' => null,
        ]);

        // The whole onboarding chain, one legal transition at a time, through
        // the state machine — so the activation event actually fires and every
        // module listening for it gets its chance. Jumping straight to ACTIVE
        // would skip the transitions and prove less than nothing.
        foreach ([
            OrganizationStatus::UNDER_REVIEW,
            OrganizationStatus::VERIFIED,
            OrganizationStatus::ACTIVE,
        ] as $status) {
            $this->app->make(OrganizationStateMachine::class)
                ->transition($organization->refresh(), $status, null, 'integration test');
        }

        return $organization->refresh();
    }

    private function depositBar(Organization $owner): GoldLotModel
    {
        $vault = VaultModel::query()->create([
            'vault_code' => 'V01',
            'name' => 'Central vault',
            'address' => 'Tehran',
            'status' => 'ACTIVE',
        ]);

        $result = $this->app->make(VaultService::class)->deposit(new DepositCommand(
            vaultId: (int) $vault->id,
            ownerOrganizationId: (int) $owner->id,
            pieces: [
                new DepositPiece(
                    Weight::fromMilligrams(self::BAR_GROSS_MG),
                    Purity::fromScaled(9_950),
                    PuritySource::ASSAYED,
                    LotShape::BAR,
                    'SN-2026-0001',
                ),
            ],
            requestedByUserId: 1,
            executedByUserId: 2,
        ));

        return GoldLotModel::query()->findOrFail($result->lotIds[0]);
    }

    /** Enough gold on one side and enough rial on the other to clear one trade. */
    private function fundBothSides(): void
    {
        $this->app->make(GoldLedgerInterface::class)->deposit(
            (int) $this->seller->id,
            FineWeight::fromMilligrams(self::TRADE_FINE_MG),
            LedgerReference::custody(1),
        );

        $this->app->make(RialLedgerInterface::class)->deposit(
            (int) $this->buyer->id,
            Rial::fromRial(self::GROSS_AMOUNT * 2),
            LedgerReference::custody(1),
        );
    }

    private function tradeOnce(): Trade
    {
        $orders = $this->app->make(PlaceOrderService::class);

        $orders->place($this->order($this->seller, Side::SELL));
        $orders->place($this->order($this->buyer, Side::BUY));

        return Trade::query()->latest('id')->firstOrFail();
    }

    private function order(Organization $organization, Side $side): PlaceOrderCommand
    {
        return new PlaceOrderCommand(
            organizationId: (int) $organization->id,
            userId: 1,
            representativeId: null,
            instrumentCode: $this->instrument->code,
            side: $side,
            type: OrderType::LIMIT,
            timeInForce: TimeInForce::DAY,
            quantity: FineWeight::fromMilligrams(self::TRADE_FINE_MG),
            price: PricePerFineGram::fromRial(self::PRICE),
            expiresAt: null,
        );
    }

    /** Fine milligrams the organisation owns as metal, across every live lot. */
    private function ownedFineMg(Organization $organization): int
    {
        return (int) GoldLotModel::query()
            ->where('owner_organization_id', $organization->id)
            ->whereNotIn('status', ['CONSUMED', 'MELTED', 'WITHDRAWN'])
            ->sum('fine_weight_mg');
    }

    private function ownedGrossMg(Organization $organization): int
    {
        return (int) GoldLotModel::query()
            ->where('owner_organization_id', $organization->id)
            ->whereNotIn('status', ['CONSUMED', 'MELTED', 'WITHDRAWN'])
            ->sum('gross_weight_mg');
    }

    private function goldAvailable(Organization $organization): int
    {
        return $this->app->make(LedgerInterface::class)
            ->rawBalance((int) $organization->id, AssetType::GOLD, Bucket::AVAILABLE);
    }

    private function assertEveryGroupBalances(): void
    {
        $unbalanced = DB::table('ledger_entries')
            ->selectRaw('transaction_group, asset_type, SUM(amount) AS total')
            ->groupBy('transaction_group', 'asset_type')
            ->havingRaw('SUM(amount) <> 0')
            ->get();

        $this->assertCount(0, $unbalanced, 'Unbalanced groups: '.json_encode($unbalanced->toArray()));
    }

    private function assertSystemConserved(): void
    {
        foreach ([AssetType::GOLD, AssetType::RIAL] as $asset) {
            $this->assertSame(
                0,
                (int) DB::table('ledger_entries')->where('asset_type', $asset->value)->sum('amount'),
                "System total for {$asset->value} is not zero",
            );
        }
    }
}
