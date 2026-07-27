<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Tests\Feature;

use App\Modules\Reporting\Application\GoldFlowReport;
use App\Modules\Reporting\Application\RialFlowReport;
use App\Modules\Reporting\Contracts\FlowFacts;
use App\Modules\Reporting\Domain\DateRange;
use App\Modules\Reporting\Tests\ReportingTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * §15.7's «قاعده الزامی»: a flow report ends with a reconciliation, and a
 * failed reconciliation is surfaced, never swallowed.
 */
final class FlowReportReconciliationTest extends ReportingTestCase
{
    private const ORG = 184;

    private const RANGE_FROM = '2026-01-01';

    private const RANGE_TO = '2026-01-31';

    #[Test]
    public function the_documented_gold_flow_adds_up_and_reconciles(): void
    {
        // §9.4's worked example, in fine milligrams.
        $this->data->gold = new FlowFacts(
            opening: 1_000_000,
            inflows: [
                'خریدها' => 1_250_000,
                'دریافت‌ها (سپرده)' => 500_000,
            ],
            outflows: [
                'فروش‌ها' => 1_180_000,
                'تحویل‌ها (برداشت)' => 320_000,
            ],
            adjustments: [
                'ری‌گیری مجدد' => -2_500,
                'افت ذوب' => -180,
                'گِردکردن' => 0,
            ],
        );

        $report = $this->goldFlow()->build(self::ORG, $this->range());

        // 1,000.000 + 1,250.000 + 500.000 − 1,180.000 − 320.000 − 2.680
        self::assertSame(1_247_320, $report->computedClosing());
        self::assertTrue($report->isReconciled());
        self::assertSame(0, $report->discrepancy());
        self::assertNull($report->warning());
    }

    #[Test]
    public function a_deliberately_injected_discrepancy_is_detected(): void
    {
        $this->data->gold = new FlowFacts(
            opening: 1_000_000,
            inflows: ['خریدها' => 1_250_000, 'دریافت‌ها (سپرده)' => 500_000],
            outflows: ['فروش‌ها' => 1_180_000, 'تحویل‌ها (برداشت)' => 320_000],
            adjustments: ['ری‌گیری مجدد' => -2_500, 'افت ذوب' => -180],
        );

        // The ledger says one gram less than the movements account for.
        $this->data->goldBalanceOverride = 1_246_320;

        $report = $this->goldFlow()->build(self::ORG, $this->range());

        self::assertSame(1_247_320, $report->computedClosing());
        self::assertSame(1_246_320, $report->independentClosing);

        self::assertFalse($report->isReconciled(), 'the report must not claim to reconcile');
        self::assertSame(1_000, $report->discrepancy());

        $warning = $report->warning();
        self::assertNotNull($warning, 'a discrepant report must carry a warning');
        self::assertStringContainsString('مغایرت', $warning);
        self::assertStringContainsString('1,247,320', $warning);
        self::assertStringContainsString('1,246,320', $warning);
    }

    #[Test]
    public function a_one_milligram_discrepancy_is_still_a_discrepancy(): void
    {
        $this->data->gold = new FlowFacts(opening: 500_000, inflows: ['خریدها' => 100_000]);
        $this->data->goldBalanceOverride = 599_999;

        $report = $this->goldFlow()->build(self::ORG, $this->range());

        self::assertFalse($report->isReconciled());
        self::assertSame(1, $report->discrepancy());
    }

    #[Test]
    public function a_ledger_read_that_cannot_be_made_is_not_treated_as_agreement(): void
    {
        $this->data->gold = new FlowFacts(opening: 500_000, inflows: ['خریدها' => 100_000]);
        $this->data->goldBalanceUnavailable = true;

        $report = $this->goldFlow()->build(self::ORG, $this->range());

        // The numbers are identical — but nothing was actually checked.
        self::assertSame(0, $report->discrepancy());
        self::assertFalse(
            $report->isReconciled(),
            'an unavailable independent read must not count as reconciled',
        );
        self::assertStringContainsString('تطبیق انجام نشد', (string) $report->warning());
    }

    #[Test]
    public function the_rial_flow_reconciles_the_same_way(): void
    {
        $this->data->rial = new FlowFacts(
            opening: 4_200_000_000,
            inflows: ['فروش طلا' => 12_000_000_000],
            outflows: ['خرید طلا' => 9_500_000_000, 'کارمزد' => 142_000_000],
        );

        $good = $this->rialFlow()->build(self::ORG, $this->range());

        self::assertSame(6_558_000_000, $good->computedClosing());
        self::assertTrue($good->isReconciled());

        $this->data->rialBalanceOverride = 6_557_000_000;

        $bad = $this->rialFlow()->build(self::ORG, $this->range());

        self::assertFalse($bad->isReconciled());
        self::assertSame(1_000_000, $bad->discrepancy());
    }

    #[Test]
    public function the_report_lines_follow_the_documented_shape(): void
    {
        $this->data->gold = new FlowFacts(
            opening: 1_000_000,
            inflows: ['خریدها' => 1_250_000],
            outflows: ['فروش‌ها' => 1_180_000],
            adjustments: ['افت ذوب' => -180],
        );

        $report = $this->goldFlow()->build(self::ORG, $this->range());
        $lines = $this->goldFlow()->lines($report);

        $labels = array_map(static fn (array $l): string => trim($l['label']), $lines);

        // §15.7: opening, inflows, outflows, adjustments, closing — in order.
        self::assertSame('موجودی ابتدای دوره', $labels[0]);
        self::assertSame('= موجودی پایان دوره', $labels[count($labels) - 1]);

        self::assertContains('خریدها', $labels);
        self::assertContains('فروش‌ها', $labels);
        self::assertContains('افت ذوب', $labels);

        // Outflows are shown as negatives so the column adds up as printed.
        $sold = array_values(array_filter($lines, static fn (array $l): bool => trim($l['label']) === 'فروش‌ها'));
        self::assertSame(-1_180_000, $sold[0]['weight_mg']);
    }

    #[Test]
    public function an_empty_period_reconciles_rather_than_erroring(): void
    {
        $report = $this->goldFlow()->build(self::ORG, $this->range());

        self::assertSame(0, $report->computedClosing());
        self::assertTrue($report->isReconciled());
    }

    private function goldFlow(): GoldFlowReport
    {
        return $this->app->make(GoldFlowReport::class);
    }

    private function rialFlow(): RialFlowReport
    {
        return $this->app->make(RialFlowReport::class);
    }

    private function range(): DateRange
    {
        return DateRange::of(self::RANGE_FROM, self::RANGE_TO);
    }
}
