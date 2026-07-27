<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Tests\Feature;

use App\Modules\Reporting\Application\ReportJobService;
use App\Modules\Reporting\Contracts\FlowFacts;
use App\Modules\Reporting\Contracts\TradeRow;
use App\Modules\Reporting\Domain\DateRange;
use App\Modules\Reporting\Domain\ReportFormat;
use App\Modules\Reporting\Domain\ReportJobStatus;
use App\Modules\Reporting\Domain\ReportType;
use App\Modules\Reporting\Tests\ReportingTestCase;
use App\Modules\Shared\Exceptions\LimitExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * §15.8 — the inline/queued split, the one-year cap and the expiring link.
 */
final class ReportJobServiceTest extends ReportingTestCase
{
    private const ORG = 184;

    private ReportJobService $jobs;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->jobs = $this->app->make(ReportJobService::class);
    }

    #[Test]
    public function a_range_longer_than_one_year_is_rejected(): void
    {
        try {
            new DateRange('2025-01-01', '2026-06-30');
            self::fail('a range beyond one year must be refused');
        } catch (LimitExceededException $e) {
            self::assertSame('report_range_days', $e->limitType);
            self::assertSame(DateRange::MAX_DAYS, $e->limit);
            self::assertGreaterThan(DateRange::MAX_DAYS, $e->requested);
        }
    }

    #[Test]
    public function exactly_one_year_is_allowed(): void
    {
        $range = new DateRange('2026-01-01', '2026-12-31');

        self::assertSame(365, $range->days());

        // A leap year is still one year.
        $leap = new DateRange('2028-01-01', '2028-12-31');
        self::assertSame(366, $leap->days());
    }

    #[Test]
    public function a_backwards_range_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DateRange('2026-02-01', '2026-01-01');
    }

    #[Test]
    public function a_small_report_runs_inline_and_is_immediately_downloadable(): void
    {
        $this->data->gold = new FlowFacts(opening: 1_000_000, inflows: ['خریدها' => 250_000]);

        $job = $this->jobs->request(
            self::ORG,
            ReportType::GOLD_FLOW,
            DateRange::of('2026-01-01', '2026-01-31'),
        );

        self::assertTrue($job->ran_inline);
        self::assertSame(ReportJobStatus::COMPLETED->value, $job->status);
        self::assertNotNull($job->download_token);
        self::assertTrue($job->isDownloadable());

        $content = $this->jobs->download((string) $job->download_token);

        self::assertNotNull($content);
        self::assertStringContainsString('گردش طلا', $content);
    }

    #[Test]
    public function a_large_report_is_queued_instead(): void
    {
        // A year of trades is far past the inline threshold.
        $job = $this->jobs->request(
            self::ORG,
            ReportType::TRADES,
            DateRange::of('2026-01-01', '2026-12-31'),
        );

        self::assertFalse($job->ran_inline);
        self::assertSame(ReportJobStatus::QUEUED->value, $job->status);
        self::assertNull($job->download_token);

        // A worker picks it up later.
        $completed = $this->jobs->run($job);

        self::assertSame(ReportJobStatus::COMPLETED->value, $completed->status);
        self::assertNotNull($completed->download_token);
    }

    #[Test]
    public function the_stored_path_is_not_derivable_from_the_job(): void
    {
        $job = $this->jobs->request(
            self::ORG,
            ReportType::GOLD_FLOW,
            DateRange::of('2026-01-01', '2026-01-05'),
        );

        $second = $this->jobs->request(
            self::ORG,
            ReportType::GOLD_FLOW,
            DateRange::of('2026-01-01', '2026-01-05'),
        );

        // A 64-character random name, nothing else.
        self::assertMatchesRegularExpression(
            '#^reports/[0-9a-f]{2}/[0-9a-f]{64}\.csv$#',
            (string) $job->file_path,
        );

        // Two identical requests land in different places, so the path cannot
        // be a function of the parameters — which is what makes guessing
        // another member's file hopeless.
        self::assertNotSame((string) $job->file_path, (string) $second->file_path);
        self::assertNotSame((string) $job->download_token, (string) $second->download_token);
    }

    #[Test]
    public function an_expired_link_stops_working(): void
    {
        $job = $this->jobs->request(
            self::ORG,
            ReportType::GOLD_FLOW,
            DateRange::of('2026-01-01', '2026-01-05'),
        );

        $token = (string) $job->download_token;

        self::assertNotNull($this->jobs->resolveToken($token));

        // 25 hours later — past the documented 24-hour window.
        $later = Carbon::now()->addHours(25);

        self::assertNull($this->jobs->resolveToken($token, $later));
        self::assertNull($this->jobs->download($token, $later));
    }

    #[Test]
    public function an_unknown_or_malformed_token_resolves_to_nothing(): void
    {
        self::assertNull($this->jobs->resolveToken('not-a-token'));
        self::assertNull($this->jobs->resolveToken(str_repeat('f', 64)));
    }

    #[Test]
    public function expiring_stale_links_clears_the_token(): void
    {
        $job = $this->jobs->request(
            self::ORG,
            ReportType::GOLD_FLOW,
            DateRange::of('2026-01-01', '2026-01-05'),
        );

        $expired = $this->jobs->expireStaleLinks(Carbon::now()->addHours(25));

        self::assertSame(1, $expired);

        $job->refresh();

        self::assertSame(ReportJobStatus::EXPIRED->value, $job->status);
        self::assertNull($job->download_token);
    }

    #[Test]
    public function running_a_completed_job_twice_does_not_mint_a_second_token(): void
    {
        $job = $this->jobs->request(
            self::ORG,
            ReportType::GOLD_FLOW,
            DateRange::of('2026-01-01', '2026-01-05'),
        );

        $token = (string) $job->download_token;

        $this->jobs->run($job);
        $this->jobs->run($job);

        self::assertSame($token, (string) $job->refresh()->download_token);
    }

    #[Test]
    public function a_trade_export_writes_both_date_columns_and_numeric_cells(): void
    {
        $this->data->trades = [
            new TradeRow(
                tradeId: 88231,
                executedOn: '2026-01-20',
                side: 'BUY',
                venue: 'ORDER_BOOK',
                fineMg: 248_750,
                purityX10k: 9_950,
                pricePerFineGram: 78_480_000,
                grossRial: 19_521_900_000,
                feeRial: 29_282_850,
            ),
        ];

        $job = $this->jobs->request(
            self::ORG,
            ReportType::TRADES,
            DateRange::of('2026-01-20', '2026-01-20'),
            ReportFormat::CSV,
        );

        $content = (string) $this->jobs->download((string) $job->download_token);

        // §15.10: a Jalali string plus a sortable Gregorian column.
        self::assertStringContainsString('1404/10/30', $content);
        self::assertStringContainsString('2026-01-20', $content);

        // Numbers unquoted and unformatted, so the cell stays a number.
        self::assertStringContainsString('19521900000', $content);
        self::assertStringNotContainsString('19,521,900,000', $content);

        // Weight in grams to three decimals.
        self::assertStringContainsString('248.750', $content);
    }

    #[Test]
    public function an_excel_export_is_a_workbook_stored_with_an_xlsx_extension(): void
    {
        $this->data->trades = [
            new TradeRow(
                tradeId: 88231,
                executedOn: '2026-01-20',
                side: 'BUY',
                venue: 'ORDER_BOOK',
                fineMg: 248_750,
                purityX10k: 9_950,
                pricePerFineGram: 78_480_000,
                grossRial: 19_521_900_000,
                feeRial: 29_282_850,
            ),
        ];

        $job = $this->jobs->request(
            self::ORG,
            ReportType::TRADES,
            DateRange::of('2026-01-20', '2026-01-20'),
            ReportFormat::EXCEL,
        );

        $content = (string) $this->jobs->download((string) $job->download_token);

        self::assertStringStartsWith('PK', $content, 'An .xlsx is a ZIP package');
        self::assertStringEndsWith('.xlsx', (string) $job->refresh()->file_path);

        // The checksum on the job row still covers the DATA, so the same period
        // exported as CSV and as a workbook is verifiably the same report.
        self::assertSame(64, strlen((string) $job->checksum));
    }

    #[Test]
    public function a_failed_run_records_the_error_on_the_job(): void
    {
        $job = $this->jobs->request(
            self::ORG,
            ReportType::TRADES,
            DateRange::of('2026-01-01', '2026-12-31'),
        );

        // A trade whose date is unparseable breaks the Jalali column.
        $this->data->trades = [
            new TradeRow(
                tradeId: 1,
                executedOn: 'not-a-date',
                side: 'BUY',
                venue: 'OTC',
                fineMg: 1_000,
                purityX10k: 9_950,
                pricePerFineGram: 1,
                grossRial: 1,
                feeRial: 0,
            ),
        ];

        try {
            $this->jobs->run($job);
            self::fail('the run should have failed');
        } catch (\Throwable) {
            // expected
        }

        $job->refresh();

        self::assertSame(ReportJobStatus::FAILED->value, $job->status);
        self::assertNotNull($job->error);
    }
}
