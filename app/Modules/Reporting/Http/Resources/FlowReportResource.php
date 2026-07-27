<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Contracts\FlowReport;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * `GET /reports/gold-flow` and `GET /reports/rial-flow`.
 *
 * The §15.7 reconciliation is carried in the payload, not left to the client to
 * compute: `is_reconciled`, the signed `discrepancy` and the Persian warning
 * text travel with the numbers, so there is no way to render the closing
 * balance without also having the warning that belongs next to it.
 *
 * Amounts are integers throughout — fine milligrams for GOLD, rial for RIAL —
 * and the `_display` companions are strings nothing ever parses back.
 *
 * @mixin FlowReport
 */
final class FlowReportResource extends ApiResource
{
    /**
     * @param  FlowReport  $resource
     * @param  array<int, array<string, mixed>>  $lines  the labelled report lines, in document order
     */
    public function __construct(mixed $resource, private readonly array $lines = [])
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FlowReport $report */
        $report = $this->resource;

        $isGold = $report->unit === 'GOLD';
        $amountKey = $isGold ? 'weight_mg' : 'rial';

        $lines = array_map(
            function (array $line) use ($request, $amountKey, $isGold): array {
                $amount = (int) $line[$amountKey];

                return [
                    'label' => (string) $line['label'],
                    'amount' => $amount,
                    'is_total' => (bool) $line['is_total'],
                ] + $this->display($request, [
                    'amount_display' => $isGold ? Display::grams($amount) : Display::rial($amount),
                ]);
            },
            $this->lines,
        );

        return [
            'unit' => $report->unit,
            'range' => $report->range->jsonSerialize(),
            'opening' => $report->facts->opening,
            'inflows' => $report->facts->inflows,
            'outflows' => $report->facts->outflows,
            'adjustments' => $report->facts->adjustments,
            'total_inflows' => $report->facts->totalInflows(),
            'total_outflows' => $report->facts->totalOutflows(),
            'total_adjustments' => $report->facts->totalAdjustments(),
            'closing_computed' => $report->computedClosing(),
            'closing_independent' => $report->independentClosing,
            'discrepancy' => $report->discrepancy(),
            'independent_read_available' => $report->independentReadAvailable,
            'is_reconciled' => $report->isReconciled(),
            'warning' => $report->warning(),
            'lines' => $lines,
        ] + $this->display($request, [
            'opening_display' => $isGold
                ? Display::grams($report->facts->opening)
                : Display::rial($report->facts->opening),
            'closing_computed_display' => $isGold
                ? Display::grams($report->computedClosing())
                : Display::rial($report->computedClosing()),
            'discrepancy_display' => $isGold
                ? Display::grams($report->discrepancy())
                : Display::rial($report->discrepancy()),
            'range_from_jalali' => Display::jalali($report->range->from, false),
            'range_to_jalali' => Display::jalali($report->range->to, false),
        ]);
    }
}
