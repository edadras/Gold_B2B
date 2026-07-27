<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Feature;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\Exceptions\SelfTransferException;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Events\TransferCompleted;
use App\Modules\Ledger\Tests\LedgerTestCase;
use App\Modules\Shared\Exceptions\InsufficientBalanceException;
use App\Modules\Shared\ValueObjects\Rial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Worked example 1, step 6 (g6): 250 g leave 184's IN_SETTLEMENT and arrive in
 * 291's AVAILABLE. Nothing is created or destroyed on the way.
 */
final class TransferTest extends LedgerTestCase
{
    use RefreshDatabase;

    private const SELLER = 184;

    private const BUYER = 291;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedger(self::SELLER, self::BUYER);
        $this->depositGold(self::SELLER, 1_000_000);
        $this->depositRial(self::BUYER, 25_000_000_000);
    }

    #[Test]
    public function a_transfer_conserves_the_total_between_two_organisations(): void
    {
        $totalBefore = $this->goldBalance(self::SELLER) + $this->goldBalance(self::BUYER);
        $systemBefore = $this->systemTotal(AssetType::GOLD);

        $this->goldLedger()->transfer(self::SELLER, self::BUYER, self::fine(250_000), LedgerReference::trade(88231));

        $this->assertSame(750_000, $this->goldBalance(self::SELLER));
        $this->assertSame(250_000, $this->goldBalance(self::BUYER));
        $this->assertSame($totalBefore, $this->goldBalance(self::SELLER) + $this->goldBalance(self::BUYER));
        $this->assertSame($systemBefore, $this->systemTotal(AssetType::GOLD));
        $this->assertSame(0, $this->systemTotal(AssetType::GOLD), 'Invariant I4');
    }

    #[Test]
    public function it_writes_one_balanced_group_of_two_entries(): void
    {
        $before = $this->entryCount();

        $result = $this->goldLedger()->transfer(
            self::SELLER,
            self::BUYER,
            self::fine(250_000),
            LedgerReference::trade(88231),
        );

        $this->assertSame($before + 2, $this->entryCount());
        $this->assertGroupSumsToZero($result->group->value);

        $rows = DB::table('ledger_entries')
            ->where('transaction_group', $result->group->value)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(-250_000, (int) $rows[0]->amount);
        $this->assertSame(EntryType::TRADE_SELL_GOLD->value, $rows[0]->entry_type);
        $this->assertSame(250_000, (int) $rows[1]->amount);
        $this->assertSame(EntryType::TRADE_BUY_GOLD->value, $rows[1]->entry_type);
    }

    #[Test]
    public function the_result_reports_both_legs(): void
    {
        $result = $this->goldLedger()->transfer(
            self::SELLER,
            self::BUYER,
            self::fine(250_000),
            LedgerReference::trade(88231),
        );

        $this->assertSame(self::SELLER, $result->fromOrganizationId);
        $this->assertSame(self::BUYER, $result->toOrganizationId);
        $this->assertSame(250_000, $result->amount);
        $this->assertSame('GOLD', $result->assetType);

        $debit = DB::table('ledger_entries')->where('id', $result->debitEntryId->value)->first();
        $credit = DB::table('ledger_entries')->where('id', $result->creditEntryId->value)->first();

        $this->assertSame(-250_000, (int) $debit->amount);
        $this->assertSame(250_000, (int) $credit->amount);
    }

    #[Test]
    public function transferring_to_the_same_organisation_is_rejected(): void
    {
        $before = $this->entryCount();

        try {
            $this->goldLedger()->transfer(self::SELLER, self::SELLER, self::fine(1_000), LedgerReference::trade(1));
            $this->fail('Expected SelfTransferException');
        } catch (SelfTransferException $e) {
            $this->assertSame(self::SELLER, $e->organizationId);
            $this->assertSame('SELF_TRANSFER', $e->errorCode());
        }

        $this->assertSame($before, $this->entryCount());
    }

    #[Test]
    public function a_transfer_beyond_the_source_balance_leaves_both_sides_untouched(): void
    {
        $before = $this->entryCount();

        $this->expectException(InsufficientBalanceException::class);

        try {
            $this->goldLedger()->transfer(
                self::SELLER,
                self::BUYER,
                self::fine(1_000_001),
                LedgerReference::trade(1),
            );
        } finally {
            $this->assertSame($before, $this->entryCount());
            $this->assertSame(1_000_000, $this->goldBalance(self::SELLER));
            $this->assertSame(0, $this->goldBalance(self::BUYER));
        }
    }

    #[Test]
    public function it_can_move_between_different_buckets_on_each_side(): void
    {
        $this->goldLedger()->moveBucket(
            self::SELLER,
            Bucket::AVAILABLE,
            Bucket::IN_SETTLEMENT,
            self::fine(250_000),
            LedgerReference::settlement(88231),
        );

        $this->goldLedger()->transfer(
            self::SELLER,
            self::BUYER,
            self::fine(250_000),
            LedgerReference::settlement(88231),
            Bucket::IN_SETTLEMENT,
            Bucket::AVAILABLE,
        );

        $this->assertSame(0, $this->goldBalance(self::SELLER, Bucket::IN_SETTLEMENT));
        $this->assertSame(750_000, $this->goldBalance(self::SELLER));
        $this->assertSame(250_000, $this->goldBalance(self::BUYER));
    }

    #[Test]
    public function rial_transfers_default_to_the_cash_trade_types(): void
    {
        $result = $this->rialLedger()->transfer(
            self::BUYER,
            self::SELLER,
            self::money(19_600_380_000),
            LedgerReference::trade(88231),
        );

        $rows = DB::table('ledger_entries')
            ->where('transaction_group', $result->group->value)
            ->orderBy('id')
            ->get();

        $this->assertSame(EntryType::TRADE_BUY_CASH->value, $rows[0]->entry_type);
        $this->assertSame(EntryType::TRADE_SELL_CASH->value, $rows[1]->entry_type);
        $this->assertSame(19_600_380_000, $this->rialBalance(self::SELLER));
        $this->assertSame(5_399_620_000, $this->rialBalance(self::BUYER));
    }

    #[Test]
    public function transfers_in_both_directions_leave_the_total_unchanged(): void
    {
        $total = fn (): int => $this->goldBalance(self::SELLER) + $this->goldBalance(self::BUYER);
        $before = $total();

        $this->goldLedger()->transfer(self::SELLER, self::BUYER, self::fine(400_000), LedgerReference::trade(1));
        $this->goldLedger()->transfer(self::BUYER, self::SELLER, self::fine(150_000), LedgerReference::trade(2));
        $this->goldLedger()->transfer(self::SELLER, self::BUYER, self::fine(50_000), LedgerReference::trade(3));

        $this->assertSame($before, $total());
        $this->assertSame(0, $this->systemTotal(AssetType::GOLD));
    }

    #[Test]
    public function it_dispatches_transfer_completed(): void
    {
        Event::fake([TransferCompleted::class]);

        $this->goldLedger()->transfer(self::SELLER, self::BUYER, self::fine(250_000), LedgerReference::trade(88231));

        Event::assertDispatched(TransferCompleted::class, function (TransferCompleted $event): bool {
            return $event->fromOrganizationId === self::SELLER
                && $event->toOrganizationId === self::BUYER
                && $event->amount === 250_000
                && $event->assetType === 'GOLD'
                && $event->referenceId === 88231;
        });
    }

    #[Test]
    public function a_gold_amount_cannot_be_handed_to_the_rial_ledger(): void
    {
        // The typed facades make this a compile-time impossibility; the generic
        // ledger enforces it at run time.
        $this->expectException(InvalidArgumentException::class);

        AssetType::GOLD->unwrap(Rial::fromRial(1));
    }
}
