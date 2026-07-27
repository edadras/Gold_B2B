<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Feature;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Tests\LedgerTestCase;
use App\Modules\Ledger\Tests\Support\RandomOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Invariant I2 — the ledger_balances cache is only ever a cache.
 *
 * If rebuilding from ledger_entries ever disagrees with the stored value, the
 * cache has drifted and the ledger cannot be trusted.
 */
final class RebuildBalanceTest extends LedgerTestCase
{
    use RandomOperations;
    use RefreshDatabase;

    private const ORGS = [184, 291, 305];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedger(...self::ORGS);
    }

    #[Test]
    public function rebuilding_an_untouched_account_yields_zero(): void
    {
        $this->assertSame(0, $this->ledger()->rebuildBalance(184, AssetType::GOLD, Bucket::IN_DISPUTE));
    }

    #[Test]
    public function rebuilding_reproduces_the_cached_balance(): void
    {
        $this->depositGold(184, 1_000_000);
        $this->goldLedger()->reserve(184, self::fine(250_000), LedgerReference::order(1));

        $this->assertSame(750_000, $this->ledger()->rebuildBalance(184, AssetType::GOLD, Bucket::AVAILABLE));
        $this->assertSame(250_000, $this->ledger()->rebuildBalance(184, AssetType::GOLD, Bucket::RESERVED));
    }

    /**
     * The headline check: 200 random operations, then every single account's
     * cached balance must equal Σ(its entries).
     */
    #[Test]
    public function rebuild_equals_the_cached_balance_after_200_random_operations(): void
    {
        $this->seedStartingBalances(self::ORGS);
        $this->runRandomOperations(self::ORGS, 200, 20260727);

        $stored = DB::table('ledger_balances')->pluck('balance', 'account_id');

        $this->assertGreaterThan(0, $this->entryCount(), 'The random walk wrote nothing');

        foreach (DB::table('ledger_accounts')->orderBy('id')->pluck('id') as $accountId) {
            $rebuilt = $this->ledger()->rebuildAccountBalance((int) $accountId);

            $this->assertSame(
                (int) ($stored[$accountId] ?? 0),
                $rebuilt,
                "Cached balance drifted from the entries on account {$accountId}",
            );
        }
    }

    #[Test]
    public function rebuilding_repairs_a_corrupted_cache_row(): void
    {
        $this->depositGold(184, 500_000);

        $accountId = (int) DB::table('ledger_accounts')
            ->where('organization_id', 184)
            ->where('asset_type', 'GOLD')
            ->where('bucket', Bucket::AVAILABLE->value)
            ->value('id');

        // Simulate drift. Only the cache is touched — entries stay append-only.
        DB::table('ledger_balances')->where('account_id', $accountId)->update(['balance' => 42]);
        $this->assertSame(42, $this->goldBalance(184));

        $this->assertSame(500_000, $this->ledger()->rebuildAccountBalance($accountId));
        $this->assertSame(500_000, $this->goldBalance(184));
    }

    #[Test]
    public function rebuilding_also_restores_entry_count_and_last_entry_id(): void
    {
        $this->depositGold(184, 500_000);
        $this->goldLedger()->reserve(184, self::fine(100_000), LedgerReference::order(1));

        $accountId = (int) DB::table('ledger_accounts')
            ->where('organization_id', 184)
            ->where('asset_type', 'GOLD')
            ->where('bucket', Bucket::AVAILABLE->value)
            ->value('id');

        DB::table('ledger_balances')->where('account_id', $accountId)->update([
            'balance' => 0,
            'entry_count' => 0,
            'last_entry_id' => null,
        ]);

        $this->ledger()->rebuildAccountBalance($accountId);

        $row = DB::table('ledger_balances')->where('account_id', $accountId)->first();
        $expectedLast = (int) DB::table('ledger_entries')->where('account_id', $accountId)->max('id');

        $this->assertSame(400_000, (int) $row->balance);
        $this->assertSame(2, (int) $row->entry_count);
        $this->assertSame($expectedLast, (int) $row->last_entry_id);
    }
}
