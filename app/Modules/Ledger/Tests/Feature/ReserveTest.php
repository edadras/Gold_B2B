<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Feature;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Events\BalanceReserved;
use App\Modules\Ledger\Tests\LedgerTestCase;
use App\Modules\Shared\Exceptions\InsufficientBalanceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Worked example 1, step 1: organisation 184 places a SELL order and 250 g move
 * from AVAILABLE to RESERVED in one balanced group.
 */
final class ReserveTest extends LedgerTestCase
{
    use RefreshDatabase;

    private const SELLER = 184;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedger(self::SELLER);
        $this->depositGold(self::SELLER, 1_000_000);
    }

    #[Test]
    public function reserve_moves_gold_from_available_to_reserved(): void
    {
        $this->goldLedger()->reserve(self::SELLER, self::fine(250_000), LedgerReference::order(44101));

        $this->assertSame(750_000, $this->goldBalance(self::SELLER));
        $this->assertSame(250_000, $this->goldBalance(self::SELLER, Bucket::RESERVED));
    }

    #[Test]
    public function reserve_writes_exactly_two_entries_whose_group_sums_to_zero(): void
    {
        $before = $this->entryCount();

        $entryId = $this->goldLedger()->reserve(self::SELLER, self::fine(250_000), LedgerReference::order(44101));

        $this->assertSame($before + 2, $this->entryCount());

        $group = DB::table('ledger_entries')->where('id', $entryId->value)->value('transaction_group');
        $rows = DB::table('ledger_entries')->where('transaction_group', $group)->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertSame(0, (int) $rows->sum('amount'));
        $this->assertGroupSumsToZero((string) $group);

        foreach ($rows as $row) {
            $this->assertSame(EntryType::RESERVE->value, $row->entry_type);
            $this->assertSame('order', $row->reference_type);
            $this->assertSame(44101, (int) $row->reference_id);
        }
    }

    #[Test]
    public function reserve_returns_the_credit_leg_landing_in_reserved(): void
    {
        $entryId = $this->goldLedger()->reserve(self::SELLER, self::fine(250_000), LedgerReference::order(44101));

        $entry = DB::table('ledger_entries')->where('id', $entryId->value)->first();

        $this->assertSame(250_000, (int) $entry->amount);
        $this->assertSame('CREDIT', $entry->direction);
        $this->assertSame(250_000, (int) $entry->balance_after);
    }

    #[Test]
    public function reserving_more_than_the_balance_throws_and_writes_no_rows(): void
    {
        $before = $this->entryCount();
        $balanceBefore = $this->goldBalance(self::SELLER);

        try {
            $this->goldLedger()->reserve(self::SELLER, self::fine(1_000_001), LedgerReference::order(1));
            $this->fail('Expected InsufficientBalanceException');
        } catch (InsufficientBalanceException $e) {
            $this->assertSame(1_000_001, $e->required);
            $this->assertSame(1_000_000, $e->available);
            $this->assertSame('GOLD', $e->asset);
            $this->assertSame('INSUFFICIENT_GOLD', $e->errorCode());
        }

        $this->assertSame($before, $this->entryCount(), 'A rejected reserve must leave no trace');
        $this->assertSame($balanceBefore, $this->goldBalance(self::SELLER));
        $this->assertSame(0, $this->goldBalance(self::SELLER, Bucket::RESERVED));
    }

    #[Test]
    public function reserving_the_entire_balance_is_allowed(): void
    {
        $this->goldLedger()->reserve(self::SELLER, self::fine(1_000_000), LedgerReference::order(1));

        $this->assertSame(0, $this->goldBalance(self::SELLER));
        $this->assertSame(1_000_000, $this->goldBalance(self::SELLER, Bucket::RESERVED));
    }

    #[Test]
    public function successive_reserves_draw_down_the_available_balance(): void
    {
        $this->goldLedger()->reserve(self::SELLER, self::fine(600_000), LedgerReference::order(1));

        $this->expectException(InsufficientBalanceException::class);
        $this->goldLedger()->reserve(self::SELLER, self::fine(600_000), LedgerReference::order(2));
    }

    #[Test]
    public function a_zero_or_negative_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->goldLedger()->reserve(self::SELLER, self::fine(0), LedgerReference::order(1));
    }

    #[Test]
    public function rial_reserves_use_the_same_path(): void
    {
        $this->depositRial(self::SELLER, 25_000_000_000);

        $this->rialLedger()->reserve(self::SELLER, self::money(19_654_437_500), LedgerReference::order(44120));

        $this->assertSame(5_345_562_500, $this->rialBalance(self::SELLER));
        $this->assertSame(19_654_437_500, $this->rialBalance(self::SELLER, Bucket::RESERVED));
    }

    #[Test]
    public function it_dispatches_balance_reserved_after_the_write(): void
    {
        Event::fake([BalanceReserved::class]);

        $this->goldLedger()->reserve(self::SELLER, self::fine(250_000), LedgerReference::order(44101));

        Event::assertDispatched(BalanceReserved::class, function (BalanceReserved $event): bool {
            return $event->organizationId === self::SELLER
                && $event->assetType === AssetType::GOLD->value
                && $event->amount === 250_000
                && $event->availableAfter === 750_000
                && $event->referenceType === 'order'
                && $event->referenceId === 44101;
        });
    }

    #[Test]
    public function the_balance_cache_tracks_entry_count_and_version(): void
    {
        $this->goldLedger()->reserve(self::SELLER, self::fine(100_000), LedgerReference::order(1));

        $row = DB::table('ledger_accounts as a')
            ->join('ledger_balances as b', 'b.account_id', '=', 'a.id')
            ->where('a.organization_id', self::SELLER)
            ->where('a.asset_type', 'GOLD')
            ->where('a.bucket', Bucket::RESERVED->value)
            ->select('b.*')
            ->first();

        $this->assertSame(100_000, (int) $row->balance);
        $this->assertSame(1, (int) $row->entry_count);
        $this->assertSame(1, (int) $row->version);
        $this->assertNotNull($row->last_entry_id);
    }
}
