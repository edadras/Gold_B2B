<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Feature;

use App\Modules\Ledger\Application\SnapshotService;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Events\BalanceDiscrepancyDetected;
use App\Modules\Ledger\Tests\LedgerTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * docs/03-domain/03-ledger.md §3.7 and §3.8 — the nightly safety net and the
 * snapshots that keep it affordable.
 */
final class ReconciliationTest extends LedgerTestCase
{
    use RefreshDatabase;

    private const ORG = 184;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedger(self::ORG, 291);
        $this->depositGold(self::ORG, 1_000_000);
        $this->goldLedger()->reserve(self::ORG, self::fine(250_000), LedgerReference::order(1));
    }

    #[Test]
    public function a_healthy_ledger_reconciles_clean(): void
    {
        $report = $this->reconciliation()->reconcile(null, true);

        $this->assertTrue($report['healthy'], json_encode($report));
        $this->assertSame(['GOLD' => 0, 'RIAL' => 0], $report['conservation']);
        $this->assertSame([], $report['discrepancies']);
        $this->assertSame([], $report['unbalanced_groups']);
        $this->assertSame([], $report['broken_hash_chains']);
    }

    #[Test]
    public function it_detects_a_drifted_balance_cache_and_emits_an_event(): void
    {
        Event::fake([BalanceDiscrepancyDetected::class]);

        $accountId = $this->accountId(self::ORG, 'GOLD', Bucket::AVAILABLE);
        DB::table('ledger_balances')->where('account_id', $accountId)->update(['balance' => 1]);

        $report = $this->reconciliation()->reconcile();

        $this->assertFalse($report['healthy']);
        $this->assertCount(1, $report['discrepancies']);
        $this->assertSame($accountId, $report['discrepancies'][0]['account_id']);
        $this->assertSame(1, $report['discrepancies'][0]['stored']);
        $this->assertSame(750_000, $report['discrepancies'][0]['computed']);
        $this->assertSame(749_999, $report['discrepancies'][0]['difference']);

        Event::assertDispatched(BalanceDiscrepancyDetected::class, function (BalanceDiscrepancyDetected $e) use ($accountId): bool {
            return $e->accountId === $accountId
                && $e->organizationId === self::ORG
                && $e->assetType === 'GOLD'
                && $e->difference === 749_999;
        });
    }

    #[Test]
    public function it_detects_an_unbalanced_group_planted_behind_the_services_back(): void
    {
        $this->plantRogueEntry();

        $report = $this->reconciliation()->reconcile();

        $this->assertFalse($report['healthy']);
        $this->assertCount(1, $report['unbalanced_groups']);
        $this->assertSame('GOLD', $report['unbalanced_groups'][0]['asset_type']);
        $this->assertSame(-250_000, $report['unbalanced_groups'][0]['sum']);
        $this->assertSame(-250_000, $report['conservation']['GOLD'], 'Conservation is broken too');
    }

    #[Test]
    public function it_detects_a_negative_balance_on_an_account_that_may_not_have_one(): void
    {
        $accountId = $this->accountId(self::ORG, 'GOLD', Bucket::AVAILABLE);
        DB::table('ledger_balances')->where('account_id', $accountId)->update(['balance' => -5]);

        $report = $this->reconciliation()->reconcile();

        $this->assertCount(1, $report['negative_balances']);
        $this->assertSame(-5, $report['negative_balances'][0]['balance']);
    }

    #[Test]
    public function it_detects_a_broken_hash_chain(): void
    {
        $entryId = (int) DB::table('ledger_entries')->orderBy('id')->value('id');
        DB::table('ledger_entries')->where('id', $entryId)->update(['description' => 'tampered', 'amount' => 1]);

        $report = $this->reconciliation()->reconcile(null, true);

        $this->assertNotSame([], $report['broken_hash_chains']);
        $this->assertContains($entryId, $report['broken_hash_chains'][0]['entry_ids']);
    }

    #[Test]
    public function it_detects_a_running_balance_that_no_longer_adds_up(): void
    {
        $entryId = (int) DB::table('ledger_entries')->orderByDesc('id')->value('id');
        DB::table('ledger_entries')->where('id', $entryId)->update(['balance_after' => 12345]);

        $broken = $this->reconciliation()->checkRunningBalances();

        $this->assertCount(1, $broken);
        $this->assertSame($entryId, $broken[0]['entry_id']);
        $this->assertSame(12345, $broken[0]['stored']);
    }

    #[Test]
    public function the_reconcile_command_succeeds_on_a_healthy_ledger(): void
    {
        $this->artisan('ledger:reconcile', ['--hashes' => true])
            ->expectsOutputToContain('Ledger is consistent.')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_reconcile_command_fails_loudly_on_a_broken_ledger(): void
    {
        $this->plantRogueEntry();

        $this->artisan('ledger:reconcile')
            ->expectsOutputToContain('Ledger reconciliation FAILED')
            ->assertExitCode(1);
    }

    #[Test]
    public function the_snapshot_command_writes_one_row_per_account(): void
    {
        $accounts = (int) DB::table('ledger_accounts')->count();

        $this->artisan('ledger:snapshot', ['--date' => '2026-07-27'])->assertExitCode(0);

        $this->assertSame($accounts, (int) DB::table('ledger_snapshots')->count());

        $accountId = $this->accountId(self::ORG, 'GOLD', Bucket::AVAILABLE);
        $snapshot = DB::table('ledger_snapshots')->where('account_id', $accountId)->first();

        $this->assertSame(750_000, (int) $snapshot->balance);
        $this->assertSame('2026-07-27', Carbon::parse($snapshot->snapshot_date)->toDateString());
    }

    #[Test]
    public function snapshots_are_idempotent_for_a_given_date(): void
    {
        $this->artisan('ledger:snapshot', ['--date' => '2026-07-27'])->assertExitCode(0);
        $this->artisan('ledger:snapshot', ['--date' => '2026-07-27'])->assertExitCode(0);

        $this->assertSame(
            (int) DB::table('ledger_accounts')->count(),
            (int) DB::table('ledger_snapshots')->count(),
        );
    }

    #[Test]
    public function a_balance_can_be_rebuilt_from_a_snapshot_plus_later_entries(): void
    {
        $snapshots = $this->app->make(SnapshotService::class);
        $snapshots->snapshotAll(Carbon::parse('2026-07-27'));

        $accountId = $this->accountId(self::ORG, 'GOLD', Bucket::AVAILABLE);
        $this->assertSame(750_000, $snapshots->balanceFromSnapshot($accountId));

        // Entries after the snapshot are picked up without re-summing history.
        $this->depositGold(self::ORG, 100_000);
        $this->assertSame(850_000, $snapshots->balanceFromSnapshot($accountId));
        $this->assertSame(850_000, $this->goldBalance(self::ORG));
    }

    #[Test]
    public function reconciliation_can_be_scoped_to_specific_accounts(): void
    {
        $accountId = $this->accountId(self::ORG, 'GOLD', Bucket::AVAILABLE);
        DB::table('ledger_balances')->where('account_id', $accountId)->update(['balance' => 1]);

        $other = $this->accountId(291, 'GOLD', Bucket::AVAILABLE);

        $this->assertSame([], $this->reconciliation()->checkBalances([$other]));
        $this->assertCount(1, $this->reconciliation()->checkBalances([$accountId]));
    }

    private function accountId(int $organizationId, string $asset, Bucket $bucket): int
    {
        return (int) DB::table('ledger_accounts')
            ->where('organization_id', $organizationId)
            ->where('asset_type', $asset)
            ->where('bucket', $bucket->value)
            ->value('id');
    }

    /**
     * Insert a single-legged entry with raw SQL — exactly the corruption
     * assertGroupBalances() would have prevented (worked example 7).
     */
    private function plantRogueEntry(): void
    {
        $accountId = $this->accountId(self::ORG, 'GOLD', Bucket::AVAILABLE);

        DB::table('ledger_entries')->insert([
            'account_id' => $accountId,
            'organization_id' => self::ORG,
            'asset_type' => AssetType::GOLD->value,
            'amount' => -250_000,
            'entry_type' => 'TRADE_SELL_GOLD',
            'direction' => 'DEBIT',
            'reference_type' => 'trade',
            'reference_id' => 99,
            'transaction_group' => '00000000-0000-4000-8000-000000000099',
            'balance_after' => 500_000,
            'created_at' => now()->format('Y-m-d H:i:s.u'),
            'row_hash' => str_repeat('0', 64),
        ]);
    }
}
