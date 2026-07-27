<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Contracts\FlowReport;
use App\Modules\Reporting\Contracts\ReportingDataSource;
use App\Modules\Reporting\Domain\DateRange;

/**
 * «گزارش گردش ریال» — the same §15.7 shape as the gold flow, in money.
 *
 * Kept as a separate class rather than a parameter on one shared report,
 * because the two are checked against different balances and their line
 * labels differ; a single class with an asset flag would spend most of its body
 * branching on that flag. The reconciliation obligation is identical and is
 * discharged the same way.
 */
final readonly class RialFlowReport
{
    public function __construct(private ReportingDataSource $data) {}

    public function build(int $organizationId, DateRange $range): FlowReport
    {
        $facts = $this->data->rialFlow($organizationId, $range);

        $independent = $this->data->closingRialBalance($organizationId, $range->to);

        return new FlowReport(
            organizationId: $organizationId,
            range: $range,
            unit: 'RIAL',
            facts: $facts,
            independentClosing: $independent ?? $facts->computedClosing(),
            independentReadAvailable: $independent !== null,
        );
    }

    /**
     * @return array<int, array{label: string, rial: int, is_total: bool}>
     */
    public function lines(FlowReport $report): array
    {
        $facts = $report->facts;

        $lines = [['label' => 'مانده ابتدای دوره', 'rial' => $facts->opening, 'is_total' => true]];

        $lines[] = ['label' => '+ ورودی‌ها', 'rial' => $facts->totalInflows(), 'is_total' => true];

        foreach ($facts->inflows as $label => $amount) {
            $lines[] = ['label' => '    '.$label, 'rial' => $amount, 'is_total' => false];
        }

        $lines[] = ['label' => '− خروجی‌ها', 'rial' => -$facts->totalOutflows(), 'is_total' => true];

        foreach ($facts->outflows as $label => $amount) {
            $lines[] = ['label' => '    '.$label, 'rial' => -$amount, 'is_total' => false];
        }

        $lines[] = ['label' => '± تعدیلات', 'rial' => $facts->totalAdjustments(), 'is_total' => true];

        foreach ($facts->adjustments as $label => $amount) {
            $lines[] = ['label' => '    '.$label, 'rial' => $amount, 'is_total' => false];
        }

        $lines[] = ['label' => '= مانده پایان دوره', 'rial' => $report->computedClosing(), 'is_total' => true];

        return $lines;
    }
}
