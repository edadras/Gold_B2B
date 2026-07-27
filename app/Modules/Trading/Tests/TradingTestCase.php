<?php

declare(strict_types=1);

namespace App\Modules\Trading\Tests;

use App\Modules\Ledger\Application\AccountProvisioner;
use App\Modules\Ledger\Contracts\GoldLedgerInterface;
use App\Modules\Ledger\Contracts\LedgerInterface;
use App\Modules\Ledger\Contracts\RialLedgerInterface;
use App\Modules\Ledger\Database\Seeders\LedgerSystemAccountsSeeder;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\LedgerServiceProvider;
use App\Modules\Pricing\Contracts\PriceReaderInterface;
use App\Modules\Pricing\PricingServiceProvider;
use App\Modules\Risk\Contracts\RiskGuardInterface;
use App\Modules\Risk\RiskServiceProvider;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Shared\ValueObjects\Rial;
use App\Modules\Trading\Application\Commands\PlaceOrderCommand;
use App\Modules\Trading\Application\MarketSessionService;
use App\Modules\Trading\Application\PlaceOrderService;
use App\Modules\Trading\Application\Results\OrderResult;
use App\Modules\Trading\Database\Seeders\InstrumentsSeeder;
use App\Modules\Trading\Domain\OrderType;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Domain\TimeInForce;
use App\Modules\Trading\Infrastructure\Models\Instrument;
use App\Modules\Trading\TradingServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Scaffolding for the Trading suite.
 *
 * The module providers are registered by hand rather than through
 * bootstrap/providers.php, which AGENT_BRIEF reserves for the coordinator.
 * Registering before the console kernel bootstraps means loadMigrationsFrom()
 * has taken effect by the time the database is migrated.
 *
 * Ledger, Pricing and Risk come along because Trading genuinely depends on
 * them: these are integration tests of the real wiring, not of a mock of it.
 * The one thing routinely swapped is RiskGuardInterface — the pre-trade gate
 * has its own suite, and letting its limits fail a matching test would be a
 * false negative about matching.
 *
 * Deliberately does NOT use RefreshDatabase: the concurrency test forks, and
 * children cannot see rows written inside a transaction that never commits.
 * Each subclass opts in to whichever isolation it needs.
 */
abstract class TradingTestCase extends BaseTestCase
{
    protected const SELLER_ORG = 184;

    protected const BUYER_ORG = 291;

    protected const SELLER_USER = 512;

    protected const BUYER_USER = 771;

    protected const INSTRUMENT = 'GOLD-995-T0';

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** System accounts, member accounts, instruments and today's open session. */
    protected function setUpMarket(int ...$organizationIds): Instrument
    {
        $this->app->make(LedgerSystemAccountsSeeder::class)->run();

        $provisioner = $this->app->make(AccountProvisioner::class);

        foreach ($organizationIds as $id) {
            $provisioner->provisionMember($id);
        }

        $this->app->make(InstrumentsSeeder::class)->run();

        $instrument = $this->instrument();

        $this->sessions()->open($instrument);

        return $instrument;
    }

    /**
     * The same fixtures, but the session stops at PRE_OPEN.
     *
     * Orders may be entered and none of them match, which is the state the
     * opening auction is designed to resolve: call sessions()->open() when the
     * book is built.
     */
    protected function setUpPreOpenMarket(int ...$organizationIds): Instrument
    {
        $this->app->make(LedgerSystemAccountsSeeder::class)->run();

        $provisioner = $this->app->make(AccountProvisioner::class);

        foreach ($organizationIds as $id) {
            $provisioner->provisionMember($id);
        }

        $this->app->make(InstrumentsSeeder::class)->run();

        $instrument = $this->instrument();

        $this->sessions()->preOpen($instrument);

        return $instrument;
    }

    protected function instrument(string $code = self::INSTRUMENT): Instrument
    {
        return Instrument::query()->where('code', $code)->firstOrFail();
    }

    /** Replaces the pre-trade gate with one that permits everything. */
    protected function allowAllRisk(): void
    {
        $this->app->instance(RiskGuardInterface::class, new PermissiveRiskGuard);
    }

    protected function refuseAllRisk(string $reason = 'limit exceeded'): void
    {
        $this->app->instance(RiskGuardInterface::class, new RefusingRiskGuard($reason));
    }

    /** A price reader that always answers, so MARKET orders can be sized. */
    protected function fixPrice(int $rial): void
    {
        $this->app->instance(PriceReaderInterface::class, new StubPriceReader($rial));
    }

    // ── placing orders ───────────────────────────────────────────────────────

    protected function placeLimit(
        int $organizationId,
        int $userId,
        Side $side,
        int $quantityMg,
        int $priceRial,
        TimeInForce $tif = TimeInForce::DAY,
        string $instrumentCode = self::INSTRUMENT,
    ): OrderResult {
        return $this->orders()->place(new PlaceOrderCommand(
            organizationId: $organizationId,
            userId: $userId,
            representativeId: null,
            instrumentCode: $instrumentCode,
            side: $side,
            type: OrderType::LIMIT,
            timeInForce: $tif,
            quantity: FineWeight::fromMilligrams($quantityMg),
            price: PricePerFineGram::fromRial($priceRial),
            expiresAt: $tif === TimeInForce::GTD ? CarbonImmutable::now()->addHour() : null,
        ));
    }

    // ── service accessors ────────────────────────────────────────────────────

    protected function orders(): PlaceOrderService
    {
        return $this->app->make(PlaceOrderService::class);
    }

    protected function sessions(): MarketSessionService
    {
        return $this->app->make(MarketSessionService::class);
    }

    protected function goldLedger(): GoldLedgerInterface
    {
        return $this->app->make(GoldLedgerInterface::class);
    }

    protected function rialLedger(): RialLedgerInterface
    {
        return $this->app->make(RialLedgerInterface::class);
    }

    protected function depositGold(int $organizationId, int $milligrams): void
    {
        $this->goldLedger()->deposit(
            $organizationId,
            FineWeight::fromMilligrams($milligrams),
            LedgerReference::custody(1),
        );
    }

    protected function depositRial(int $organizationId, int $amount): void
    {
        $this->rialLedger()->deposit(
            $organizationId,
            Rial::fromRial($amount),
            LedgerReference::custody(1),
        );
    }

    // ── ledger assertions ────────────────────────────────────────────────────

    protected function goldBalance(int $organizationId, Bucket $bucket = Bucket::AVAILABLE): int
    {
        return $this->rawBalance($organizationId, AssetType::GOLD, $bucket);
    }

    protected function rialBalance(int $organizationId, Bucket $bucket = Bucket::AVAILABLE): int
    {
        return $this->rawBalance($organizationId, AssetType::RIAL, $bucket);
    }

    private function rawBalance(int $organizationId, AssetType $asset, Bucket $bucket): int
    {
        return $this->app->make(LedgerInterface::class)->rawBalance($organizationId, $asset, $bucket);
    }

    /** Invariant I1: every transaction group nets to zero, per asset. */
    protected function assertEveryLedgerGroupBalances(): void
    {
        $unbalanced = DB::table('ledger_entries')
            ->selectRaw('transaction_group, asset_type, SUM(amount) AS total')
            ->groupBy('transaction_group', 'asset_type')
            ->havingRaw('SUM(amount) <> 0')
            ->get();

        $this->assertCount(
            0,
            $unbalanced,
            'Unbalanced ledger groups: '.json_encode($unbalanced->toArray()),
        );
    }

    /** Invariant I4: the whole system nets to zero for each asset. */
    protected function assertSystemConserved(): void
    {
        foreach ([AssetType::GOLD, AssetType::RIAL] as $asset) {
            $total = (int) DB::table('ledger_entries')
                ->where('asset_type', $asset->value)
                ->sum('amount');

            $this->assertSame(0, $total, "System total for {$asset->value} is not zero");
        }
    }
}
