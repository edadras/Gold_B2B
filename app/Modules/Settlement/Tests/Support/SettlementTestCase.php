<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Tests\Support;

use App\Modules\Custody\CustodyServiceProvider;
use App\Modules\Ledger\Application\AccountProvisioner;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Application\ReconciliationService;
use App\Modules\Ledger\Contracts\GoldLedgerInterface;
use App\Modules\Ledger\Contracts\RialLedgerInterface;
use App\Modules\Ledger\Database\Seeders\LedgerSystemAccountsSeeder;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Ledger\LedgerServiceProvider;
use App\Modules\Risk\RiskServiceProvider;
use App\Modules\Settlement\Contracts\LotMovementPort;
use App\Modules\Settlement\SettlementServiceProvider;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Scaffolding for the Settlement suite.
 *
 * Registers Ledger, Custody, Risk and Settlement explicitly instead of relying
 * on bootstrap/providers.php: AGENT_BRIEF reserves that file for the
 * coordinator, so the module's tests stand up their own container. They are
 * registered after the framework has booted — Custody and Settlement merge
 * configuration in register(), which needs the config repository — and before
 * RefreshDatabase migrates, so every module's migrations are in place.
 *
 * LotMovementPort is bound to the real Custody-backed adapter here, so the
 * worked-example tests exercise genuine lot ownership transfers rather than the
 * no-op production default.
 */
abstract class SettlementTestCase extends TestCase
{
    use CreatesSettlementFixtures;
    use RefreshDatabase;

    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        // After bootstrap, not before: Custody and Settlement both call
        // mergeConfigFrom() in register(), which needs the config repository to
        // exist. Application::register() is idempotent, so this stays safe once
        // the coordinator lists these providers in bootstrap/providers.php.
        $this->app->register(LedgerServiceProvider::class);
        $this->app->register(CustodyServiceProvider::class);
        $this->app->register(RiskServiceProvider::class);
        $this->app->register(SettlementServiceProvider::class);

        // The genuine Custody-backed port, in place of the production no-op.
        $this->app->bind(LotMovementPort::class, CustodyLotMovementAdapter::class);
    }

    // ── ledger scaffolding ───────────────────────────────────────────────────

    protected function setUpLedger(int ...$organizationIds): void
    {
        $this->app->make(LedgerSystemAccountsSeeder::class)->run();

        $provisioner = $this->app->make(AccountProvisioner::class);

        foreach ($organizationIds as $id) {
            $provisioner->provisionMember($id);
        }
    }

    protected function depositGold(int $organizationId, int $milligrams): void
    {
        $this->app->make(GoldLedgerInterface::class)->deposit(
            $organizationId,
            FineWeight::fromMilligrams($milligrams),
            LedgerReference::custody(1),
        );
    }

    protected function depositRial(int $organizationId, int $amount): void
    {
        $this->app->make(RialLedgerInterface::class)->deposit(
            $organizationId,
            Rial::fromRial($amount),
            LedgerReference::custody(1),
        );
    }

    protected function ledger(): LedgerService
    {
        return $this->app->make(LedgerService::class);
    }

    protected function goldBalance(int $organizationId, Bucket $bucket = Bucket::AVAILABLE): int
    {
        return $this->ledger()->rawBalance($organizationId, AssetType::GOLD, $bucket);
    }

    protected function rialBalance(int $organizationId, Bucket $bucket = Bucket::AVAILABLE): int
    {
        return $this->ledger()->rawBalance($organizationId, AssetType::RIAL, $bucket);
    }

    protected function systemAccountBalance(SystemAccountCode $code, AssetType $asset): int
    {
        return (int) DB::table('ledger_accounts as a')
            ->join('ledger_balances as b', 'b.account_id', '=', 'a.id')
            ->where('a.system_account_code', $code->value)
            ->where('a.asset_type', $asset->value)
            ->sum('b.balance');
    }

    // ── invariants ───────────────────────────────────────────────────────────

    /** Σ of every entry of one asset, across the whole system. Always 0 (I4). */
    protected function systemTotal(AssetType $asset): int
    {
        return (int) DB::table('ledger_entries')
            ->where('asset_type', $asset->value)
            ->sum('amount');
    }

    protected function assertGroupSumsToZero(string $transactionGroup): void
    {
        $sums = DB::table('ledger_entries')
            ->where('transaction_group', $transactionGroup)
            ->groupBy('asset_type')
            ->selectRaw('asset_type, SUM(amount) AS total')
            ->pluck('total', 'asset_type');

        $this->assertNotEmpty($sums, "Transaction group {$transactionGroup} has no entries");

        foreach ($sums as $asset => $total) {
            $this->assertSame(0, (int) $total, "Group {$transactionGroup} does not balance for {$asset}");
        }
    }

    /** Every group in the ledger balances, per asset. */
    protected function assertEveryGroupBalances(): void
    {
        $groups = DB::table('ledger_entries')->distinct()->pluck('transaction_group');

        foreach ($groups as $group) {
            $this->assertGroupSumsToZero((string) $group);
        }
    }

    protected function assertLedgerConserved(): void
    {
        $this->assertSame(0, $this->systemTotal(AssetType::GOLD), 'Gold ledger does not sum to zero');
        $this->assertSame(0, $this->systemTotal(AssetType::RIAL), 'Rial ledger does not sum to zero');
        $this->assertTrue(
            $this->app->make(ReconciliationService::class)->reconcile(null, true)['healthy'],
            'Ledger reconciliation reported a discrepancy',
        );
    }
}
