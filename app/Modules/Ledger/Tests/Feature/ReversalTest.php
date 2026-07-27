<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Feature;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\Exceptions\AlreadyReversedException;
use App\Modules\Ledger\Domain\Exceptions\LedgerEntryNotFoundException;
use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Events\EntryReversed;
use App\Modules\Ledger\Infrastructure\Models\LedgerEntryModel;
use App\Modules\Ledger\Tests\LedgerTestCase;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;

/**
 * docs/03-domain/03-ledger.md §3.6, option الف.
 *
 * ledger_entries stays append-only: a correction is a new opposite entry plus a
 * row in ledger_reversals. The original is never touched.
 */
final class ReversalTest extends LedgerTestCase
{
    use RefreshDatabase;

    private const ORG = 184;

    private const REQUESTER = 7;

    private const APPROVER = 9;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedger(self::ORG);
        $this->depositGold(self::ORG, 1_000_000);
    }

    #[Test]
    public function it_writes_an_entry_with_the_opposite_amount(): void
    {
        $original = $this->goldLedger()->debit(
            self::ORG,
            Bucket::AVAILABLE,
            self::fine(2_680),
            EntryType::ASSAY_ADJUSTMENT,
            LedgerReference::custody(4599),
        );

        $this->assertSame(997_320, $this->goldBalance(self::ORG));

        $reversalId = $this->goldLedger()->reverse($original, 'assay was re-run', self::REQUESTER, self::APPROVER);

        $originalRow = DB::table('ledger_entries')->where('id', $original->value)->first();
        $reversalRow = DB::table('ledger_entries')->where('id', $reversalId->value)->first();

        $this->assertSame(-(int) $originalRow->amount, (int) $reversalRow->amount);
        $this->assertSame($originalRow->account_id, $reversalRow->account_id);
        $this->assertSame(EntryType::REVERSAL->value, $reversalRow->entry_type);
        $this->assertStringContainsString('assay was re-run', (string) $reversalRow->description);
        $this->assertSame(1_000_000, $this->goldBalance(self::ORG), 'The reversal undoes the effect');
    }

    #[Test]
    public function it_records_a_ledger_reversals_row_and_leaves_the_original_untouched(): void
    {
        $original = $this->goldLedger()->withdraw(self::ORG, self::fine(200_000), LedgerReference::custody(221));
        $before = DB::table('ledger_entries')->where('id', $original->value)->first();

        $reversalId = $this->goldLedger()->reverse($original, 'wrong lot', self::REQUESTER, self::APPROVER);

        $link = DB::table('ledger_reversals')->where('original_entry_id', $original->value)->first();

        $this->assertNotNull($link);
        $this->assertSame($reversalId->value, (int) $link->reversal_entry_id);
        $this->assertSame('wrong lot', $link->reason);
        $this->assertSame(self::REQUESTER, (int) $link->requested_by_user_id);
        $this->assertSame(self::APPROVER, (int) $link->approved_by_user_id);

        $after = DB::table('ledger_entries')->where('id', $original->value)->first();
        $this->assertEquals($before, $after, 'The original row must be byte-identical afterwards');
    }

    #[Test]
    public function reversing_twice_is_rejected(): void
    {
        $original = $this->goldLedger()->withdraw(self::ORG, self::fine(100_000), LedgerReference::custody(1));
        $first = $this->goldLedger()->reverse($original, 'mistake', self::REQUESTER, self::APPROVER);

        try {
            $this->goldLedger()->reverse($original, 'again', self::REQUESTER, self::APPROVER);
            $this->fail('Expected AlreadyReversedException');
        } catch (AlreadyReversedException $e) {
            $this->assertSame($original->value, $e->originalEntryId);
            $this->assertSame($first->value, $e->existingReversalEntryId);
        }

        $this->assertSame(1, DB::table('ledger_reversals')->where('original_entry_id', $original->value)->count());
    }

    #[Test]
    public function a_second_reversal_attempt_writes_no_entries(): void
    {
        $original = $this->goldLedger()->withdraw(self::ORG, self::fine(100_000), LedgerReference::custody(1));
        $this->goldLedger()->reverse($original, 'mistake', self::REQUESTER, self::APPROVER);

        $before = $this->entryCount();
        $balanceBefore = $this->goldBalance(self::ORG);

        $this->expectException(AlreadyReversedException::class);

        try {
            $this->goldLedger()->reverse($original, 'again', self::REQUESTER, self::APPROVER);
        } finally {
            $this->assertSame($before, $this->entryCount());
            $this->assertSame($balanceBefore, $this->goldBalance(self::ORG));
        }
    }

    #[Test]
    public function the_same_person_cannot_request_and_approve(): void
    {
        $original = $this->goldLedger()->withdraw(self::ORG, self::fine(100_000), LedgerReference::custody(1));

        $this->expectException(OperationNotPermittedException::class);
        $this->goldLedger()->reverse($original, 'mistake', self::REQUESTER, self::REQUESTER);
    }

    #[Test]
    public function a_reversal_must_state_a_reason(): void
    {
        $original = $this->goldLedger()->withdraw(self::ORG, self::fine(100_000), LedgerReference::custody(1));

        $this->expectException(InvalidArgumentException::class);
        $this->goldLedger()->reverse($original, '', self::REQUESTER, self::APPROVER);
    }

    #[Test]
    public function reversing_an_entry_that_does_not_exist_is_rejected(): void
    {
        $this->expectException(LedgerEntryNotFoundException::class);

        $this->goldLedger()->reverse(LedgerEntryId::fromInt(999_999), 'nope', self::REQUESTER, self::APPROVER);
    }

    /**
     * A reversal posts two legs: the opposite entry on the original account and
     * a matching leg on SUSPENSE. Reversing only one side of a balanced pair
     * would otherwise break invariant I4 across the system.
     */
    #[Test]
    public function the_reversal_group_balances_and_conservation_holds(): void
    {
        $original = $this->goldLedger()->withdraw(self::ORG, self::fine(200_000), LedgerReference::custody(1));

        $reversalId = $this->goldLedger()->reverse($original, 'wrong lot', self::REQUESTER, self::APPROVER);

        $group = DB::table('ledger_entries')->where('id', $reversalId->value)->value('transaction_group');
        $this->assertGroupSumsToZero((string) $group);
        $this->assertSame(2, DB::table('ledger_entries')->where('transaction_group', $group)->count());
        $this->assertSame(0, $this->systemTotal(AssetType::GOLD), 'Invariant I4');
    }

    #[Test]
    public function reversing_both_legs_of_a_pair_returns_suspense_to_zero(): void
    {
        $this->provision(291);
        $result = $this->goldLedger()->transfer(self::ORG, 291, self::fine(100_000), LedgerReference::trade(1));

        $this->goldLedger()->reverse($result->debitEntryId, 'trade busted', self::REQUESTER, self::APPROVER);
        $this->goldLedger()->reverse($result->creditEntryId, 'trade busted', self::REQUESTER, self::APPROVER);

        $suspense = (int) DB::table('ledger_accounts as a')
            ->join('ledger_balances as b', 'b.account_id', '=', 'a.id')
            ->where('a.system_account_code', 'SUSPENSE')
            ->where('a.asset_type', 'GOLD')
            ->value('b.balance');

        $this->assertSame(0, $suspense);
        $this->assertSame(1_000_000, $this->goldBalance(self::ORG));
        $this->assertSame(0, $this->goldBalance(291));
    }

    #[Test]
    public function it_dispatches_entry_reversed(): void
    {
        $original = $this->goldLedger()->withdraw(self::ORG, self::fine(100_000), LedgerReference::custody(1));

        Event::fake([EntryReversed::class]);

        $reversalId = $this->goldLedger()->reverse($original, 'mistake', self::REQUESTER, self::APPROVER);

        Event::assertDispatched(EntryReversed::class, function (EntryReversed $event) use ($original, $reversalId): bool {
            return $event->originalEntryId === $original->value
                && $event->reversalEntryId === $reversalId->value
                && $event->reversedAmount === 100_000
                && $event->requestedByUserId === self::REQUESTER
                && $event->approvedByUserId === self::APPROVER;
        });
    }

    #[Test]
    public function ledger_entries_cannot_be_updated(): void
    {
        $entry = LedgerEntryModel::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $entry->update(['amount' => 1]);
    }

    #[Test]
    public function ledger_entries_cannot_be_deleted(): void
    {
        $entry = LedgerEntryModel::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $entry->delete();
    }
}
