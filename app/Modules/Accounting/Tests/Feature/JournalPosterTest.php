<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Tests\Feature;

use App\Modules\Accounting\Application\PostingRules;
use App\Modules\Accounting\Contracts\JournalPosterInterface;
use App\Modules\Accounting\Contracts\PostingContext;
use App\Modules\Accounting\Contracts\VoucherDraft;
use App\Modules\Accounting\Contracts\VoucherLine;
use App\Modules\Accounting\Domain\AccountCode;
use App\Modules\Accounting\Domain\EntryStatus;
use App\Modules\Accounting\Domain\LineSet;
use App\Modules\Accounting\Domain\PostingRule;
use App\Modules\Accounting\Domain\SourceType;
use App\Modules\Accounting\Tests\AccountingTestCase;
use App\Modules\Shared\Exceptions\UnbalancedTransactionException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * The two invariants of §9.2, and the idempotency mechanism of §9.7.
 */
final class JournalPosterTest extends AccountingTestCase
{
    private const ORG = 184;

    private JournalPosterInterface $poster;

    private PostingRules $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->poster = $this->app->make(JournalPosterInterface::class);
        $this->rules = $this->app->make(PostingRules::class);
    }

    #[Test]
    public function the_documented_purchase_voucher_balances_in_both_columns(): void
    {
        // §9.3: 250 g gross at purity 995 → 248.750 g fine,
        // 19,521,900,000 rial plus a 29,282,850 fee.
        $result = $this->poster->post($this->rules->build(
            PostingRule::PURCHASE,
            new PostingContext(
                organizationId: self::ORG,
                sourceId: 88231,
                entryDate: '2026-01-20',
                sourceType: SourceType::TRADE,
                description: 'خرید 250 گرم خالص — TRD-88231',
                fineMg: 248_750,
                grossRial: 19_521_900_000,
                feeRial: 29_282_850,
            ),
        ));

        $this->assertVoucherBalances($result->journalEntryId);

        $totals = $this->totalsFor($result->journalEntryId);

        self::assertSame(19_551_182_850, $totals['debit_rial']);
        self::assertSame(19_551_182_850, $totals['credit_rial']);
        self::assertSame(248_750, $totals['debit_fine_mg']);
        self::assertSame(248_750, $totals['credit_fine_mg']);
    }

    #[Test]
    public function the_documented_sale_voucher_balances_in_both_columns(): void
    {
        // §9.3: 150 g fine sold for 12,000,000,000, COGS 11,550,000,000,
        // fee 12,000,000 → both sides total 23,550,000,000.
        $result = $this->poster->post($this->rules->build(
            PostingRule::SALE,
            new PostingContext(
                organizationId: self::ORG,
                sourceId: 88_232,
                entryDate: '2026-01-20',
                sourceType: SourceType::TRADE,
                fineMg: 150_000,
                grossRial: 12_000_000_000,
                feeRial: 12_000_000,
                cogsRial: 11_550_000_000,
            ),
        ));

        $totals = $this->totalsFor($result->journalEntryId);

        self::assertSame(23_550_000_000, $totals['debit_rial']);
        self::assertSame(23_550_000_000, $totals['credit_rial']);
        $this->assertVoucherBalances($result->journalEntryId);
    }

    #[Test]
    public function both_balance_invariants_hold_over_randomised_postings(): void
    {
        mt_srand(20_260_120);

        $rules = [
            PostingRule::PURCHASE,
            PostingRule::SALE,
            PostingRule::GOLD_DEPOSIT,
            PostingRule::GOLD_WITHDRAWAL,
            PostingRule::SEND_TO_REFINING,
            PostingRule::MELT_LOSS,
            PostingRule::REFINING_COST,
            PostingRule::ASSAY_ADJUSTMENT,
            PostingRule::FEE,
            PostingRule::PENALTY,
            PostingRule::COUNTERPARTY_SETTLEMENT,
        ];

        $posted = 0;

        for ($i = 1; $i <= 120; $i++) {
            $rule = $rules[$i % count($rules)];

            $fineMg = mt_rand(1, 5_000_000);
            $gross = mt_rand(1, 200_000) * 1_000_000;

            $context = new PostingContext(
                organizationId: self::ORG + ($i % 3),
                sourceId: $i,
                entryDate: '2026-01-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT),
                sourceType: SourceType::MANUAL,
                fineMg: $fineMg,
                grossRial: $gross,
                feeRial: mt_rand(0, 50) * 1_000_000,
                cogsRial: mt_rand(1, 150_000) * 1_000_000,
                amountRial: mt_rand(1, 10_000) * 1_000_000,
            );

            $result = $this->poster->post($this->rules->build($rule, $context));
            $this->assertVoucherBalances($result->journalEntryId);
            $posted++;
        }

        self::assertSame(120, $posted);

        // And the journal as a whole foots, per organisation and overall.
        $global = DB::table('journal_lines')
            ->selectRaw('SUM(debit_rial) dr, SUM(credit_rial) cr, SUM(debit_fine_mg) dg, SUM(credit_fine_mg) cg')
            ->first();

        self::assertSame((int) $global->dr, (int) $global->cr);
        self::assertSame((int) $global->dg, (int) $global->cg);
    }

    #[Test]
    public function redelivering_the_same_event_posts_exactly_once(): void
    {
        $draft = $this->rules->build(PostingRule::PURCHASE, new PostingContext(
            organizationId: self::ORG,
            sourceId: 99_001,
            entryDate: '2026-01-20',
            sourceType: SourceType::TRADE,
            fineMg: 100_000,
            grossRial: 7_500_000_000,
            feeRial: 11_250_000,
        ));

        $first = $this->poster->post($draft);
        $second = $this->poster->post($draft);
        $third = $this->poster->post($draft);

        self::assertTrue($first->wasCreated());
        self::assertTrue($second->alreadyExisted);
        self::assertTrue($third->alreadyExisted);

        self::assertSame($first->journalEntryId, $second->journalEntryId);
        self::assertSame($first->voucherNo, $third->voucherNo);

        self::assertSame(1, DB::table('journal_entries')
            ->where('organization_id', self::ORG)
            ->where('source_type', SourceType::TRADE->value)
            ->where('source_id', 99_001)
            ->count());

        self::assertSame($first->lineCount, DB::table('journal_lines')
            ->where('journal_entry_id', $first->journalEntryId)
            ->count());
    }

    #[Test]
    public function an_unbalanced_rial_column_is_refused_before_anything_is_written(): void
    {
        $draft = new VoucherDraft(
            organizationId: self::ORG,
            sourceType: SourceType::MANUAL,
            sourceId: 5_001,
            entryDate: '2026-01-20',
            description: 'سند نامتوازن',
            lines: [
                VoucherLine::debitRial(AccountCode::BANK, 1_000_000),
                VoucherLine::creditRial(AccountCode::CAPITAL, 999_999),
            ],
        );

        try {
            $this->poster->post($draft);
            self::fail('An unbalanced voucher must be refused');
        } catch (UnbalancedTransactionException $e) {
            self::assertSame('RIAL', $e->assetType);
            self::assertSame(1, $e->sum);
        }

        self::assertSame(0, DB::table('journal_entries')->count());
        self::assertSame(0, DB::table('journal_lines')->count());
    }

    #[Test]
    public function an_unbalanced_gold_column_is_refused_even_when_the_rial_column_foots(): void
    {
        $draft = new VoucherDraft(
            organizationId: self::ORG,
            sourceType: SourceType::MANUAL,
            sourceId: 5_002,
            entryDate: '2026-01-20',
            description: 'ستون طلا نامتوازن',
            lines: [
                VoucherLine::debitRial(AccountCode::GOLD_IN_VAULT, 1_000_000),
                VoucherLine::creditRial(AccountCode::PLATFORM_RIAL_BALANCE, 1_000_000),
                VoucherLine::debitGold(AccountCode::GOLD_IN_VAULT, 10_000),
                VoucherLine::creditGold(AccountCode::OPEN_TRADE_COMMITMENTS, 9_000),
            ],
        );

        $this->expectException(UnbalancedTransactionException::class);

        $this->poster->post($draft);
    }

    #[Test]
    public function a_rial_line_may_not_carry_a_weight(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new VoucherLine(
            account: AccountCode::PLATFORM_RIAL_BALANCE,
            set: LineSet::RIAL,
            creditRial: 100,
            debitFineMg: 5,
        );
    }

    #[Test]
    public function a_gold_line_may_only_use_a_gold_bearing_account(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VoucherLine::debitGold(AccountCode::PLATFORM_RIAL_BALANCE, 1_000);
    }

    #[Test]
    public function reversing_a_voucher_mirrors_it_and_never_deletes_anything(): void
    {
        $original = $this->poster->post($this->rules->build(PostingRule::PURCHASE, new PostingContext(
            organizationId: self::ORG,
            sourceId: 77_001,
            entryDate: '2026-01-20',
            sourceType: SourceType::TRADE,
            fineMg: 50_000,
            grossRial: 3_800_000_000,
            feeRial: 5_700_000,
        )));

        $reversal = $this->poster->reverse($original->journalEntryId, 'اشتباه در ثبت');

        self::assertTrue($reversal->wasCreated());
        self::assertNotSame($original->journalEntryId, $reversal->journalEntryId);

        self::assertSame(
            EntryStatus::REVERSED->value,
            DB::table('journal_entries')->where('id', $original->journalEntryId)->value('status'),
        );

        // Both vouchers are still present; nothing was removed.
        self::assertSame(2, DB::table('journal_entries')->count());

        // Original plus mirror cancel out exactly.
        $combined = DB::table('journal_lines')
            ->whereIn('journal_entry_id', [$original->journalEntryId, $reversal->journalEntryId])
            ->selectRaw('SUM(debit_rial) dr, SUM(credit_rial) cr, SUM(debit_fine_mg) dg, SUM(credit_fine_mg) cg')
            ->first();

        self::assertSame((int) $combined->dr, (int) $combined->cr);
        self::assertSame((int) $combined->dg, (int) $combined->cg);

        // Reversing again returns the same mirror rather than posting a second.
        $again = $this->poster->reverse($original->journalEntryId, 'اشتباه در ثبت');
        self::assertSame($reversal->journalEntryId, $again->journalEntryId);
    }

    #[Test]
    public function voucher_numbers_are_sequential_within_an_organisation(): void
    {
        $numbers = [];

        for ($i = 1; $i <= 3; $i++) {
            $numbers[] = $this->poster->post($this->rules->build(PostingRule::FEE, new PostingContext(
                organizationId: self::ORG,
                sourceId: 4_000 + $i,
                entryDate: '2026-01-20',
                sourceType: SourceType::FEE,
                feeRial: 1_000_000 * $i,
            )))->voucherNo;
        }

        // 2026-01-20 Gregorian is 1404-10-30 Jalali (۳۰ دی ۱۴۰۴).
        self::assertSame(['GB-1404-10-00001', 'GB-1404-10-00002', 'GB-1404-10-00003'], $numbers);
    }

    /** Asserts §9.2's invariant for one voucher, entry-wide and per line set. */
    private function assertVoucherBalances(int $journalEntryId): void
    {
        $totals = $this->totalsFor($journalEntryId);

        self::assertSame(
            $totals['debit_rial'],
            $totals['credit_rial'],
            "rial column of voucher {$journalEntryId} does not balance",
        );
        self::assertSame(
            $totals['debit_fine_mg'],
            $totals['credit_fine_mg'],
            "gold column of voucher {$journalEntryId} does not balance",
        );

        foreach ([LineSet::RIAL, LineSet::GOLD] as $set) {
            $perSet = DB::table('journal_lines')
                ->where('journal_entry_id', $journalEntryId)
                ->where('line_set', $set->value)
                ->selectRaw('COALESCE(SUM(debit_rial),0) dr, COALESCE(SUM(credit_rial),0) cr, '
                    .'COALESCE(SUM(debit_fine_mg),0) dg, COALESCE(SUM(credit_fine_mg),0) cg')
                ->first();

            self::assertSame((int) $perSet->dr, (int) $perSet->cr, "{$set->value} set rial imbalance");
            self::assertSame((int) $perSet->dg, (int) $perSet->cg, "{$set->value} set gold imbalance");
        }
    }

    /** @return array{debit_rial: int, credit_rial: int, debit_fine_mg: int, credit_fine_mg: int} */
    private function totalsFor(int $journalEntryId): array
    {
        $row = DB::table('journal_lines')
            ->where('journal_entry_id', $journalEntryId)
            ->selectRaw('COALESCE(SUM(debit_rial),0) dr, COALESCE(SUM(credit_rial),0) cr, '
                .'COALESCE(SUM(debit_fine_mg),0) dg, COALESCE(SUM(credit_fine_mg),0) cg')
            ->first();

        return [
            'debit_rial' => (int) $row->dr,
            'credit_rial' => (int) $row->cr,
            'debit_fine_mg' => (int) $row->dg,
            'credit_fine_mg' => (int) $row->cg,
        ];
    }
}
