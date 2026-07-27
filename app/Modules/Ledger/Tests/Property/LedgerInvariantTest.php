<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Property;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Infrastructure\Models\LedgerEntryModel;
use App\Modules\Ledger\Tests\LedgerTestCase;
use App\Modules\Ledger\Tests\Support\RandomOperations;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The ten mandatory invariants of docs/03-domain/03-ledger.md §3.9, checked
 * against a few hundred randomised operations across eight organisations.
 *
 * «هیچ کد مالی بدون تست property-based merge نمی‌شود» — this file is that gate.
 * Run just this group with:
 *
 *     vendor/bin/phpunit app/Modules/Ledger/Tests --group ledger-invariants
 */
#[Group('ledger-invariants')]
final class LedgerInvariantTest extends LedgerTestCase
{
    use RandomOperations;
    use RefreshDatabase;

    /** Eight organisations, as in the guide's §2.7 sample. */
    private const ORGS = [101, 102, 103, 104, 105, 106, 107, 108];

    private const ITERATIONS = 400;

    private const SEED = 20260727;

    /** @var array<string, int> */
    private array $tally = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger(...self::ORGS);
        $this->seedStartingBalances(self::ORGS);
        $this->tally = $this->runRandomOperations(self::ORGS, self::ITERATIONS, self::SEED);
    }

    #[Test]
    public function the_random_walk_actually_exercised_the_ledger(): void
    {
        // A property test that silently does nothing proves nothing.
        $this->assertGreaterThan(200, $this->entryCount(), 'The random walk barely wrote anything');
        $this->assertGreaterThan(10, count($this->tally), 'Too few distinct operations were attempted');
        $this->assertGreaterThan(0, DB::table('ledger_reversals')->count(), 'No reversals were exercised');
    }

    /** I1 — Σ(entries of one transaction_group, same asset_type) = 0. */
    #[Test]
    public function i1_every_transaction_group_sums_to_zero_per_asset(): void
    {
        $unbalanced = $this->reconciliation()->unbalancedGroups();

        $this->assertSame([], $unbalanced, 'Unbalanced groups: '.json_encode($unbalanced));
    }

    /** I2 — the stored balance equals Σ(entries) for every account. */
    #[Test]
    public function i2_stored_balance_matches_the_computed_balance(): void
    {
        $discrepancies = $this->reconciliation()->checkBalances();

        $this->assertSame([], $discrepancies, 'Balance drift: '.json_encode($discrepancies));
    }

    /** I3 — AVAILABLE / RESERVED / IN_SETTLEMENT / IN_DISPUTE never go negative. */
    #[Test]
    public function i3_member_buckets_never_hold_a_negative_balance(): void
    {
        $negatives = $this->reconciliation()->checkNonNegative();

        $this->assertSame([], $negatives, 'Negative balances: '.json_encode($negatives));

        // Belt and braces: assert it straight from the entries as well, so a
        // corrupted cache cannot hide a genuinely negative position.
        $rows = DB::table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')
            ->where('a.allows_negative', false)
            ->groupBy('e.account_id')
            ->havingRaw('SUM(e.amount) < 0')
            ->select('e.account_id')
            ->get();

        $this->assertCount(0, $rows, 'An account computed a negative balance from its entries');
    }

    /** I4 — Σ(all entries of an asset) across the whole system = 0. */
    #[Test]
    public function i4_the_system_conserves_every_asset(): void
    {
        $this->assertSame(0, $this->systemTotal(AssetType::GOLD), 'Gold was created or destroyed');
        $this->assertSame(0, $this->systemTotal(AssetType::RIAL), 'Rial was created or destroyed');
        $this->assertSame(
            ['GOLD' => 0, 'RIAL' => 0],
            $this->reconciliation()->conservationByAsset(),
        );
    }

    /**
     * I5 — the member population's holdings are exactly what came in through
     * the system accounts.
     *
     * The doc states this against physical lots (Σ GOLD in member accounts =
     * Σ fine_weight of active lots). Custody does not exist yet, so the
     * ledger-side half is asserted here: whatever members hold must be mirrored
     * by an equal and opposite position on the system accounts. The physical
     * comparison belongs to the Custody module's own reconciliation.
     */
    #[Test]
    public function i5_member_holdings_are_backed_by_the_system_accounts(): void
    {
        foreach ([AssetType::GOLD, AssetType::RIAL] as $asset) {
            $members = $this->memberTotal($asset);
            $system = (int) DB::table('ledger_entries')
                ->where('asset_type', $asset->value)
                ->where('organization_id', 0)
                ->sum('amount');

            $this->assertSame(-$system, $members, "{$asset->value} member holdings are not backed");
        }

        $this->assertGreaterThan(0, $this->memberTotal(AssetType::GOLD), 'Members hold no gold at all');
    }

    /**
     * I6 — the entry count only ever goes up.
     *
     * Checked behaviourally rather than by id contiguity: rolled-back
     * transactions consume auto-increment values, so gaps in the id sequence
     * are normal and prove nothing either way.
     */
    #[Test]
    public function i6_the_entry_count_never_decreases(): void
    {
        $count = $this->entryCount();
        $this->assertGreaterThan(0, $count);

        // A rejected operation must not remove rows either.
        try {
            $this->goldLedger()->withdraw(101, self::fine(999_999_999), LedgerReference::custody(1));
        } catch (DomainException) {
            // expected
        }
        $this->assertGreaterThanOrEqual($count, $this->entryCount());

        // A further successful operation only adds.
        $this->goldLedger()->deposit(101, self::fine(1_000), LedgerReference::custody(2));
        $this->assertSame($count + 2, $this->entryCount());

        // And the model refuses deletion outright.
        $this->expectException(LogicException::class);
        LedgerEntryModel::query()->firstOrFail()->delete();
    }

    /** I7 — each reversal produced exactly one entry with the opposite amount. */
    #[Test]
    public function i7_every_reversal_has_exactly_one_opposite_entry(): void
    {
        $links = DB::table('ledger_reversals')->get();

        $this->assertGreaterThan(0, $links->count());

        foreach ($links as $link) {
            $original = DB::table('ledger_entries')->where('id', $link->original_entry_id)->first();
            $reversal = DB::table('ledger_entries')->where('id', $link->reversal_entry_id)->first();

            $this->assertNotNull($original);
            $this->assertNotNull($reversal);
            $this->assertSame(
                -(int) $original->amount,
                (int) $reversal->amount,
                "Reversal {$link->reversal_entry_id} does not negate entry {$link->original_entry_id}",
            );
            $this->assertSame((int) $original->account_id, (int) $reversal->account_id);
            $this->assertSame(EntryType::REVERSAL->value, $reversal->entry_type);
            $this->assertNotSame(
                (int) $link->requested_by_user_id,
                (int) $link->approved_by_user_id,
                'Four-eyes rule violated',
            );
        }
    }

    /** I8 — no entry was reversed twice. */
    #[Test]
    public function i8_no_entry_is_reversed_more_than_once(): void
    {
        $duplicateOriginals = DB::table('ledger_reversals')
            ->select('original_entry_id')
            ->groupBy('original_entry_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->assertCount(0, $duplicateOriginals);

        $duplicateReversals = DB::table('ledger_reversals')
            ->select('reversal_entry_id')
            ->groupBy('reversal_entry_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->assertCount(0, $duplicateReversals);
    }

    /** I9 — balance_after equals the running sum up to that entry. */
    #[Test]
    public function i9_balance_after_equals_the_running_sum(): void
    {
        $broken = $this->reconciliation()->checkRunningBalances();

        $this->assertSame([], $broken, 'balance_after drifted: '.json_encode(array_slice($broken, 0, 5)));
    }

    /** I10 — every account's hash chain verifies. */
    #[Test]
    public function i10_the_hash_chain_is_intact(): void
    {
        $broken = $this->reconciliation()->verifyHashChains();

        $this->assertSame([], $broken, 'Broken hash chains: '.json_encode($broken));
    }

    /** All of them at once, the way `ledger:reconcile` reports it. */
    #[Test]
    public function the_full_reconciliation_reports_a_healthy_ledger(): void
    {
        $report = $this->reconciliation()->reconcile(null, true);

        $this->assertTrue($report['healthy'], 'Reconciliation report: '.json_encode($report));
    }

    /**
     * Tampering must be detectable. Rewriting a historical amount behind the
     * application's back has to fail both I2/I9 and the hash chain.
     */
    #[Test]
    public function tampering_with_a_stored_entry_is_detected(): void
    {
        $entry = DB::table('ledger_entries')->orderBy('id')->first();

        DB::table('ledger_entries')->where('id', $entry->id)->update(['amount' => (int) $entry->amount + 1]);

        $report = $this->reconciliation()->reconcile(null, true);

        $this->assertFalse($report['healthy']);
        $this->assertNotSame([], $report['broken_hash_chains'], 'The hash chain did not notice the edit');
        $this->assertNotSame([], $report['discrepancies'], 'The balance check did not notice the edit');
    }
}
