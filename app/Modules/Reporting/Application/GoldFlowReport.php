<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Contracts\FlowReport;
use App\Modules\Reporting\Contracts\ReportingDataSource;
use App\Modules\Reporting\Domain\DateRange;

/**
 * «گزارش گردش طلا» — §9.4 and the standard flow shape of §15.7.
 *
 * The report adds up the period's movements to a closing balance, then reads
 * the closing balance again from the ledger and compares. §15.7 makes that
 * comparison mandatory: «هر گزارش گردش باید در انتها یک بررسی تطبیق داشته
 * باشد … اگر یکی نبود، گزارش با هشدار قرمز نمایش داده می‌شود».
 *
 * The comparison only means something because the two numbers come from
 * different places — the flow rows on one side, an independent balance read on
 * the other. Computing the "independent" figure from the same rows would give a
 * check that can never fail and therefore never help.
 */
final readonly class GoldFlowReport
{
    public function __construct(private ReportingDataSource $data) {}

    public function build(int $organizationId, DateRange $range): FlowReport
    {
        $facts = $this->data->goldFlow($organizationId, $range);

        // The independent read, as at the last day of the range.
        $independent = $this->data->closingGoldBalance($organizationId, $range->to);

        return new FlowReport(
            organizationId: $organizationId,
            range: $range,
            unit: 'GOLD',
            facts: $facts,
            // Fall back to the computed figure so the object is still valid;
            // `independentReadAvailable: false` is what stops it being treated
            // as reconciled, rather than a fabricated match.
            independentClosing: $independent ?? $facts->computedClosing(),
            independentReadAvailable: $independent !== null,
        );
    }

    /**
     * The report as printed in §9.4: labelled lines in document order, in fine
     * grams to three decimals.
     *
     * @return array<int, array{label: string, weight_mg: int, is_total: bool}>
     */
    public function lines(FlowReport $report): array
    {
        $facts = $report->facts;
        $lines = [['label' => 'موجودی ابتدای دوره', 'weight_mg' => $facts->opening, 'is_total' => true]];

        $lines[] = ['label' => '+ خریدها و دریافت‌ها', 'weight_mg' => $facts->totalInflows(), 'is_total' => true];

        foreach ($facts->inflows as $label => $amount) {
            $lines[] = ['label' => '    '.$label, 'weight_mg' => $amount, 'is_total' => false];
        }

        $lines[] = ['label' => '− فروش‌ها و تحویل‌ها', 'weight_mg' => -$facts->totalOutflows(), 'is_total' => true];

        foreach ($facts->outflows as $label => $amount) {
            $lines[] = ['label' => '    '.$label, 'weight_mg' => -$amount, 'is_total' => false];
        }

        $lines[] = ['label' => '± تعدیلات', 'weight_mg' => $facts->totalAdjustments(), 'is_total' => true];

        foreach ($facts->adjustments as $label => $amount) {
            $lines[] = ['label' => '    '.$label, 'weight_mg' => $amount, 'is_total' => false];
        }

        $lines[] = ['label' => '= موجودی پایان دوره', 'weight_mg' => $report->computedClosing(), 'is_total' => true];

        return $lines;
    }
}
