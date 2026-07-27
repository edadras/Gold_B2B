<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Feature;

use App\Modules\Ledger\Application\GoldLedgerService;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Application\RialLedgerService;
use App\Modules\Ledger\Contracts\GoldLedgerInterface;
use App\Modules\Ledger\Contracts\GroupWriter;
use App\Modules\Ledger\Contracts\LedgerInterface;
use App\Modules\Ledger\Contracts\RialLedgerInterface;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Ledger\Tests\LedgerTestCase;
use App\Modules\Shared\Exceptions\InsufficientBalanceException;
use App\Modules\Shared\Exceptions\UnbalancedTransactionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * postGroup(), credit()/debit() and the fee/rounding paths.
 *
 * Worked example 7 lives here: a group with a missing counter-leg must roll the
 * whole transaction back rather than leave a hole in the ledger.
 */
final class PostGroupTest extends LedgerTestCase
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

    /** Worked example 7 — the forgotten counter-leg. */
    #[Test]
    public function an_unbalanced_group_is_rejected_and_leaves_nothing_behind(): void
    {
        $before = $this->entryCount();
        $balanceBefore = $this->goldBalance(self::SELLER);

        try {
            $this->ledger()->postGroup(function (GroupWriter $writer): void {
                $writer->post(
                    self::SELLER,
                    AssetType::GOLD,
                    Bucket::AVAILABLE,
                    -250_000,
                    EntryType::TRADE_SELL_GOLD,
                    LedgerReference::trade(88231),
                );
                // ❌ the counter-leg was forgotten
            });
            $this->fail('Expected UnbalancedTransactionException');
        } catch (UnbalancedTransactionException $e) {
            $this->assertSame('GOLD', $e->assetType);
            $this->assertSame(-250_000, $e->sum);
            $this->assertSame('UNBALANCED_TRANSACTION', $e->errorCode());
            $this->assertSame(500, $e->httpStatus());
        }

        $this->assertSame($before, $this->entryCount(), 'The rollback left rows behind');
        $this->assertSame($balanceBefore, $this->goldBalance(self::SELLER));
    }

    /** Worked example 1, g5 — a four-leg cash settlement with two fees. */
    #[Test]
    public function it_posts_a_multi_leg_group_in_one_transaction(): void
    {
        $this->rialLedger()->moveBucket(
            self::BUYER,
            Bucket::AVAILABLE,
            Bucket::IN_SETTLEMENT,
            self::money(19_649_430_000),
            LedgerReference::settlement(88231),
        );

        $ref = LedgerReference::settlement(88231);

        $group = $this->ledger()->postGroup(function (GroupWriter $writer) use ($ref): void {
            $writer->post(self::BUYER, AssetType::RIAL, Bucket::IN_SETTLEMENT, -19_649_430_000, EntryType::TRADE_BUY_CASH, $ref);
            $writer->post(self::SELLER, AssetType::RIAL, Bucket::AVAILABLE, 19_600_380_000, EntryType::TRADE_SELL_CASH, $ref);
            $writer->postSystem(SystemAccountCode::FEE_INCOME, AssetType::RIAL, 29_430_000, EntryType::FEE_INCOME, $ref);
            $writer->postSystem(SystemAccountCode::FEE_INCOME, AssetType::RIAL, 19_620_000, EntryType::FEE_INCOME, $ref);
        });

        $this->assertGroupSumsToZero($group->value);
        $this->assertSame(4, DB::table('ledger_entries')->where('transaction_group', $group->value)->count());
        $this->assertSame(19_600_380_000, $this->rialBalance(self::SELLER));
        $this->assertSame(0, $this->rialBalance(self::BUYER, Bucket::IN_SETTLEMENT));

        $feeIncome = (int) DB::table('ledger_accounts as a')
            ->join('ledger_balances as b', 'b.account_id', '=', 'a.id')
            ->where('a.system_account_code', SystemAccountCode::FEE_INCOME->value)
            ->value('b.balance');

        $this->assertSame(49_050_000, $feeIncome, 'Worked example 1: SYSTEM/FEE_INCOME ends at 49,050,000');
        $this->assertSame(0, $this->systemTotal(AssetType::RIAL));
    }

    /** Worked example 2, g7 — one milligram of split dust goes to the system. */
    #[Test]
    public function rounding_dust_lands_on_the_rounding_difference_account(): void
    {
        $this->goldLedger()->debit(
            self::SELLER,
            Bucket::AVAILABLE,
            self::fine(1),
            EntryType::ROUNDING,
            LedgerReference::custody(1512),
        );

        $this->assertSame(999_999, $this->goldBalance(self::SELLER));

        $rounding = (int) DB::table('ledger_accounts as a')
            ->join('ledger_balances as b', 'b.account_id', '=', 'a.id')
            ->where('a.system_account_code', SystemAccountCode::ROUNDING_DIFFERENCE->value)
            ->where('a.asset_type', 'GOLD')
            ->value('b.balance');

        $this->assertSame(1, $rounding);
        $this->assertSame(0, $this->systemTotal(AssetType::GOLD));
    }

    #[Test]
    public function a_fee_charge_credits_the_platform_and_debits_the_member(): void
    {
        $entryId = $this->rialLedger()->chargeFee(self::BUYER, self::money(29_430_000), LedgerReference::trade(88231));

        $group = DB::table('ledger_entries')->where('id', $entryId->value)->value('transaction_group');
        $rows = DB::table('ledger_entries')->where('transaction_group', $group)->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertGroupSumsToZero((string) $group);

        $member = $rows->firstWhere('organization_id', self::BUYER);
        $system = $rows->firstWhere('organization_id', 0);

        $this->assertSame(-29_430_000, (int) $member->amount);
        $this->assertSame(EntryType::FEE_CHARGE->value, $member->entry_type);
        $this->assertSame(29_430_000, (int) $system->amount);
        $this->assertSame(EntryType::FEE_INCOME->value, $system->entry_type);
    }

    #[Test]
    public function a_deposit_is_offset_against_the_external_gold_account(): void
    {
        $externalIn = (int) DB::table('ledger_accounts as a')
            ->join('ledger_balances as b', 'b.account_id', '=', 'a.id')
            ->where('a.system_account_code', SystemAccountCode::EXTERNAL_GOLD_IN->value)
            ->value('b.balance');

        // The seller's 1,000,000 mg came from outside the system.
        $this->assertSame(-1_000_000, $externalIn);
        $this->assertSame(0, $this->systemTotal(AssetType::GOLD), 'Invariant I4');
    }

    #[Test]
    public function the_payable_bucket_is_the_only_one_that_may_go_negative(): void
    {
        $this->ledger()->postGroup(function (GroupWriter $writer): void {
            $ref = LedgerReference::settlement(1);
            $writer->post(self::BUYER, AssetType::RIAL, Bucket::PAYABLE, -300_000_000, EntryType::NETTING_SETTLE, $ref);
            $writer->postSystem(SystemAccountCode::CLEARING, AssetType::RIAL, 300_000_000, EntryType::NETTING_SETTLE, $ref);
        });

        $this->assertSame(-300_000_000, $this->rialBalance(self::BUYER, Bucket::PAYABLE));
        $this->assertSame(0, $this->systemTotal(AssetType::RIAL));
    }

    #[Test]
    public function a_member_bucket_cannot_be_driven_negative(): void
    {
        $before = $this->entryCount();

        $this->expectException(InsufficientBalanceException::class);

        try {
            $this->ledger()->postGroup(function (GroupWriter $writer): void {
                $ref = LedgerReference::settlement(1);
                $writer->post(self::SELLER, AssetType::GOLD, Bucket::AVAILABLE, -2_000_000, EntryType::TRADE_SELL_GOLD, $ref);
                $writer->post(self::BUYER, AssetType::GOLD, Bucket::AVAILABLE, 2_000_000, EntryType::TRADE_BUY_GOLD, $ref);
            });
        } finally {
            $this->assertSame($before, $this->entryCount());
        }
    }

    #[Test]
    public function a_zero_amount_entry_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->ledger()->postGroup(function (GroupWriter $writer): void {
            $writer->post(
                self::SELLER,
                AssetType::GOLD,
                Bucket::AVAILABLE,
                0,
                EntryType::MANUAL_ADJUSTMENT,
                LedgerReference::adjustment(1),
            );
        });
    }

    #[Test]
    public function a_gold_entry_type_cannot_be_posted_to_a_rial_account(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->ledger()->postGroup(function (GroupWriter $writer): void {
            $writer->post(
                self::SELLER,
                AssetType::RIAL,
                Bucket::AVAILABLE,
                100,
                EntryType::TRADE_BUY_GOLD,
                LedgerReference::trade(1),
            );
        });
    }

    #[Test]
    public function the_service_is_resolvable_through_every_contract(): void
    {
        $this->assertInstanceOf(
            LedgerService::class,
            $this->app->make(LedgerInterface::class),
        );
        $this->assertInstanceOf(
            GoldLedgerService::class,
            $this->app->make(GoldLedgerInterface::class),
        );
        $this->assertInstanceOf(
            RialLedgerService::class,
            $this->app->make(RialLedgerInterface::class),
        );
    }
}
