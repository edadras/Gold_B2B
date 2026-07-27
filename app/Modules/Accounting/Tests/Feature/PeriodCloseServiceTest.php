<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Tests\Feature;

use App\Modules\Accounting\Application\PeriodCloseService;
use App\Modules\Accounting\Application\PostingRules;
use App\Modules\Accounting\Contracts\JournalPosterInterface;
use App\Modules\Accounting\Contracts\PostingContext;
use App\Modules\Accounting\Domain\Exceptions\ClosedPeriodException;
use App\Modules\Accounting\Domain\PostingRule;
use App\Modules\Accounting\Domain\SourceType;
use App\Modules\Accounting\Tests\AccountingTestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * §9.7 — «بستن دوره: پس از بستن، سند جدید در آن دوره ثبت نمی‌شود؛
 * اصلاح با سند اصلاحی در دوره جاری».
 */
final class PeriodCloseServiceTest extends AccountingTestCase
{
    private const ORG = 184;

    private PeriodCloseService $periods;

    private JournalPosterInterface $poster;

    private PostingRules $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periods = $this->app->make(PeriodCloseService::class);
        $this->poster = $this->app->make(JournalPosterInterface::class);
        $this->rules = $this->app->make(PostingRules::class);
    }

    #[Test]
    public function a_closed_period_rejects_new_postings(): void
    {
        $this->periods->open(self::ORG, '2025-12-01', '2025-12-31', '1404-09');
        $this->periods->close(self::ORG, '1404-09');

        $this->expectException(ClosedPeriodException::class);

        $this->poster->post($this->feeVoucher(sourceId: 1, entryDate: '2025-12-15'));
    }

    #[Test]
    public function an_open_period_accepts_postings(): void
    {
        $this->periods->open(self::ORG, '2025-12-01', '2025-12-31', '1404-09');

        $result = $this->poster->post($this->feeVoucher(sourceId: 2, entryDate: '2025-12-15'));

        self::assertTrue($result->wasCreated());
        self::assertSame(
            1,
            DB::table('journal_entries')->where('entry_date', '2025-12-15')->count(),
        );
    }

    #[Test]
    public function a_date_with_no_period_row_is_unmanaged_and_postable(): void
    {
        $result = $this->poster->post($this->feeVoucher(sourceId: 3, entryDate: '2025-07-04'));

        self::assertTrue($result->wasCreated());
    }

    #[Test]
    public function a_correction_for_a_closed_period_lands_in_the_current_open_period(): void
    {
        $this->periods->open(self::ORG, '2025-12-01', '2025-12-31', '1404-09');
        $this->periods->close(self::ORG, '1404-09');

        $current = $this->periods->open(self::ORG, '2026-01-01', '2026-12-31', '1404-10');

        $result = $this->poster->postCorrection($this->feeVoucher(sourceId: 4, entryDate: '2025-12-15'));

        self::assertTrue($result->wasCreated());

        $entryDate = (string) DB::table('journal_entries')
            ->where('id', $result->journalEntryId)
            ->value('entry_date');

        self::assertNotSame('2025-12-15', substr($entryDate, 0, 10), 'the closed date must not be used');
        self::assertGreaterThanOrEqual('2026-01-01', substr($entryDate, 0, 10));

        self::assertSame(
            (int) $current->id,
            (int) DB::table('journal_entries')->where('id', $result->journalEntryId)->value('accounting_period_id'),
        );
    }

    #[Test]
    public function closing_twice_is_harmless(): void
    {
        $this->periods->open(self::ORG, '2025-12-01', '2025-12-31', '1404-09');

        $first = $this->periods->close(self::ORG, '1404-09');
        $second = $this->periods->close(self::ORG, '1404-09');

        self::assertSame('CLOSED', $second->status);
        self::assertEquals($first->closed_at, $second->closed_at);
    }

    #[Test]
    public function closing_snapshots_the_trial_balance(): void
    {
        $this->periods->open(self::ORG, '2025-12-01', '2025-12-31', '1404-09');

        $this->poster->post($this->feeVoucher(sourceId: 5, entryDate: '2025-12-10', amount: 4_000_000));
        $this->poster->post($this->feeVoucher(sourceId: 6, entryDate: '2025-12-11', amount: 6_000_000));

        $closed = $this->periods->close(self::ORG, '1404-09');

        self::assertSame(10_000_000, (int) $closed->closing_debit_rial);
        self::assertSame(10_000_000, (int) $closed->closing_credit_rial);
    }

    #[Test]
    public function overlapping_periods_are_refused(): void
    {
        $this->periods->open(self::ORG, '2025-12-01', '2025-12-31', '1404-09');

        $this->expectException(\App\Modules\Shared\Exceptions\OperationNotPermittedException::class);

        $this->periods->open(self::ORG, '2025-12-15', '2026-01-15', '1404-10');
    }

    private function feeVoucher(int $sourceId, string $entryDate, int $amount = 1_000_000): \App\Modules\Accounting\Contracts\VoucherDraft
    {
        return $this->rules->build(PostingRule::FEE, new PostingContext(
            organizationId: self::ORG,
            sourceId: $sourceId,
            entryDate: $entryDate,
            sourceType: SourceType::FEE,
            feeRial: $amount,
        ));
    }
}
