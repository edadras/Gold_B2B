<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Feature;

use App\Modules\Ledger\Contracts\ManualAdjustmentPoster;
use App\Modules\Ledger\Contracts\PostedAdjustment;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\Exceptions\OffsetAccountMismatchException;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Ledger\Tests\LedgerTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * The published contract for a manual balance correction, §1.10.
 *
 * It exists so the admin panel does not have to write ledger rows itself. It
 * did, for a while, in an adapter that reproduced the lock order, the running
 * balance and the hash chain — and a hash chain reproduced *almost* correctly
 * does not fail on the day it is written. It fails in the nightly verification,
 * over a row nobody can now explain. So the property under test is not only
 * "the balance moved": it is that the correction is indistinguishable from any
 * other ledger write.
 */
final class ManualAdjustmentTest extends LedgerTestCase
{
    use RefreshDatabase;

    private const MEMBER = 184;

    private const CHECKER_USER = 4_211;

    private const REQUEST = 77;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedger(self::MEMBER);
        $this->depositGold(self::MEMBER, 1_000_000);
    }

    #[Test]
    public function a_correction_moves_the_balance_and_books_the_difference_to_the_offset(): void
    {
        // The assay came back 5 g light: the member has less gold than the
        // ledger says, and the shortfall is the platform's assay variance.
        $posted = $this->poster()->post(
            organizationId: self::MEMBER,
            asset: AssetType::GOLD,
            signedAmount: -5_000,
            offset: SystemAccountCode::ASSAY_VARIANCE,
            adjustmentRequestId: self::REQUEST,
            description: 'کسری ری‌گیری شمش ۱۲۴',
            postedByUserId: self::CHECKER_USER,
        );

        $this->assertCount(2, $posted->entryIds);
        $this->assertSame(995_000, $this->goldBalance(self::MEMBER));

        $entries = DB::table('ledger_entries')
            ->where('transaction_group', $posted->transactionGroup)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $entries);
        $this->assertSame(0, (int) $entries->sum('amount'), 'The pair must net to zero');
        $this->assertSame(-5_000, (int) $entries[0]->amount);
        $this->assertSame(5_000, (int) $entries[1]->amount);
    }

    #[Test]
    public function every_leg_is_attributed_to_the_checker_who_approved_it(): void
    {
        // Nobody is logged in — this can be posted from a console command — and
        // the row must still name the person who authorised it. Attribution is
        // the only thing that makes dual control legible after the fact.
        $this->assertNull(auth()->id());

        $posted = $this->postAdjustment();

        foreach ($this->entriesOf($posted->transactionGroup) as $entry) {
            $this->assertSame(self::CHECKER_USER, (int) $entry->created_by_user_id);
            $this->assertSame('adjustment', $entry->reference_type);
            $this->assertSame(self::REQUEST, (int) $entry->reference_id);
        }
    }

    #[Test]
    public function the_rows_carry_a_hash_chain_like_any_other_entry(): void
    {
        $posted = $this->postAdjustment();

        foreach ($this->entriesOf($posted->transactionGroup) as $entry) {
            $this->assertNotNull($entry->row_hash);
            $this->assertSame(64, strlen((string) $entry->row_hash), 'sha256, hex');
        }
    }

    #[Test]
    public function the_cached_balance_moves_with_the_entry(): void
    {
        $this->postAdjustment();

        $accountId = DB::table('ledger_accounts')
            ->where('organization_id', self::MEMBER)
            ->where('asset_type', AssetType::GOLD->value)
            ->where('bucket', Bucket::AVAILABLE->value)
            ->value('id');

        $this->assertSame(
            995_000,
            (int) DB::table('ledger_balances')->where('account_id', $accountId)->value('balance'),
        );
    }

    #[Test]
    public function a_gold_only_offset_cannot_absorb_a_rial_correction(): void
    {
        // Refused at the boundary rather than left to fail on the account
        // lookup, so the operator can be told which accounts would work.
        $this->expectException(OffsetAccountMismatchException::class);

        $this->poster()->post(
            organizationId: self::MEMBER,
            asset: AssetType::RIAL,
            signedAmount: -1_000,
            offset: SystemAccountCode::ASSAY_VARIANCE,
            adjustmentRequestId: self::REQUEST,
            description: 'اشتباه',
            postedByUserId: self::CHECKER_USER,
        );
    }

    #[Test]
    public function an_adjustment_of_zero_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->poster()->post(
            organizationId: self::MEMBER,
            asset: AssetType::GOLD,
            signedAmount: 0,
            offset: SystemAccountCode::SUSPENSE,
            adjustmentRequestId: self::REQUEST,
            description: 'هیچ',
            postedByUserId: self::CHECKER_USER,
        );
    }

    #[Test]
    public function a_correction_that_would_overdraw_the_member_leaves_nothing_behind(): void
    {
        $before = DB::table('ledger_entries')->count();

        try {
            $this->poster()->post(
                organizationId: self::MEMBER,
                asset: AssetType::GOLD,
                signedAmount: -2_000_000,
                offset: SystemAccountCode::SUSPENSE,
                adjustmentRequestId: self::REQUEST,
                description: 'بیش از موجودی',
                postedByUserId: self::CHECKER_USER,
            );

            $this->fail('An overdrawing adjustment must be refused');
        } catch (Throwable) {
            // The refusal itself is asserted by the count below: a half-written
            // group would be far worse than the wrong exception class.
        }

        $this->assertSame($before, DB::table('ledger_entries')->count());
        $this->assertSame(1_000_000, $this->goldBalance(self::MEMBER));
    }

    #[Test]
    public function the_platform_side_of_the_correction_is_visible_in_its_own_account(): void
    {
        // The offset leg is what stops a correction from conjuring gold: the
        // 5 g the member lost has to be somewhere.
        $this->postAdjustment();

        $offsetBalance = (int) DB::table('ledger_balances')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_balances.account_id')
            ->where('ledger_accounts.system_account_code', SystemAccountCode::ASSAY_VARIANCE->value)
            ->where('ledger_accounts.asset_type', AssetType::GOLD->value)
            ->value('ledger_balances.balance');

        $this->assertSame(5_000, $offsetBalance);
    }

    private function postAdjustment(): PostedAdjustment
    {
        return $this->poster()->post(
            organizationId: self::MEMBER,
            asset: AssetType::GOLD,
            signedAmount: -5_000,
            offset: SystemAccountCode::ASSAY_VARIANCE,
            adjustmentRequestId: self::REQUEST,
            description: 'کسری ری‌گیری شمش ۱۲۴',
            postedByUserId: self::CHECKER_USER,
        );
    }

    /** @return Collection<int, object> */
    private function entriesOf(string $group): Collection
    {
        return DB::table('ledger_entries')->where('transaction_group', $group)->get();
    }

    private function poster(): ManualAdjustmentPoster
    {
        return $this->app->make(ManualAdjustmentPoster::class);
    }
}
