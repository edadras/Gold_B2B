<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests;

use App\Modules\Ledger\Application\AccountProvisioner;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Application\ReconciliationService;
use App\Modules\Ledger\Contracts\GoldLedgerInterface;
use App\Modules\Ledger\Contracts\RialLedgerInterface;
use App\Modules\Ledger\Database\Seeders\LedgerSystemAccountsSeeder;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\LedgerServiceProvider;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Shared scaffolding for the ledger suite.
 *
 * Registers LedgerServiceProvider explicitly rather than relying on
 * bootstrap/providers.php: AGENT_BRIEF reserves that file for the coordinator,
 *
 * Deliberately does NOT use RefreshDatabase itself — the concurrency test needs
 * committed rows that forked children can see, so each subclass opts in.
 */
abstract class LedgerTestCase extends BaseTestCase
{
    // ── fixtures ─────────────────────────────────────────────────────────────

    protected function seedSystemAccounts(): void
    {
        $this->app->make(LedgerSystemAccountsSeeder::class)->run();
    }

    /** Provision the member accounts for the given organisation ids. */
    protected function provision(int ...$organizationIds): void
    {
        $provisioner = $this->app->make(AccountProvisioner::class);

        foreach ($organizationIds as $id) {
            $provisioner->provisionMember($id);
        }
    }

    /** System accounts plus the given members, ready to trade. */
    protected function setUpLedger(int ...$organizationIds): void
    {
        $this->seedSystemAccounts();
        $this->provision(...$organizationIds);
    }

    protected function depositGold(int $organizationId, int $milligrams): void
    {
        $this->goldLedger()->deposit($organizationId, self::fine($milligrams), LedgerReference::custody(1));
    }

    protected function depositRial(int $organizationId, int $amount): void
    {
        $this->rialLedger()->deposit($organizationId, self::money($amount), LedgerReference::custody(1));
    }

    // ── service accessors ────────────────────────────────────────────────────

    protected function ledger(): LedgerService
    {
        return $this->app->make(LedgerService::class);
    }

    protected function goldLedger(): GoldLedgerInterface
    {
        return $this->app->make(GoldLedgerInterface::class);
    }

    protected function rialLedger(): RialLedgerInterface
    {
        return $this->app->make(RialLedgerInterface::class);
    }

    protected function reconciliation(): ReconciliationService
    {
        return $this->app->make(ReconciliationService::class);
    }

    // ── value helpers ────────────────────────────────────────────────────────

    protected static function fine(int $milligrams): FineWeight
    {
        return FineWeight::fromMilligrams($milligrams);
    }

    protected static function money(int $rial): Rial
    {
        return Rial::fromRial($rial);
    }

    protected function goldBalance(int $organizationId, Bucket $bucket = Bucket::AVAILABLE): int
    {
        return $this->ledger()->rawBalance($organizationId, AssetType::GOLD, $bucket);
    }

    protected function rialBalance(int $organizationId, Bucket $bucket = Bucket::AVAILABLE): int
    {
        return $this->ledger()->rawBalance($organizationId, AssetType::RIAL, $bucket);
    }

    // ── ledger-wide assertions ───────────────────────────────────────────────

    protected function entryCount(): int
    {
        return (int) DB::table('ledger_entries')->count();
    }

    /** Σ of every entry of one asset, across the whole system. Must always be 0. */
    protected function systemTotal(AssetType $asset): int
    {
        return (int) DB::table('ledger_entries')
            ->where('asset_type', $asset->value)
            ->sum('amount');
    }

    /** Σ of one asset across member accounts only — the population's holdings. */
    protected function memberTotal(AssetType $asset): int
    {
        return (int) DB::table('ledger_entries')
            ->where('asset_type', $asset->value)
            ->where('organization_id', '!=', 0)
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
}
