<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Domain\DateRange;
use App\Modules\Reporting\Infrastructure\Models\DailyOrgSummaryModel;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * `GET /reports/daily-profit` — the §15.9 nightly rollup, read back.
 *
 * Each day carries its own `is_reconciled` flag and its discrepancies, and the
 * range-level summary lists the dates that failed rather than merely counting
 * them: a member whose Tuesday does not foot needs to know it was Tuesday.
 */
final class DailyProfitResource extends ApiResource
{
    /** @param array{rows: array<int, DailyOrgSummaryModel>, totals: array<string, int>, is_reconciled: bool, unreconciled_days: array<int, string>} $resource */
    public function __construct(mixed $resource, private readonly ?DateRange $range = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{rows: array<int, DailyOrgSummaryModel>, totals: array<string, int>, is_reconciled: bool, unreconciled_days: array<int, string>} $report */
        $report = $this->resource;

        $totals = $report['totals'];

        return [
            'range' => $this->range?->jsonSerialize(),
            'days' => array_map(
                fn (DailyOrgSummaryModel $row): array => [
                    'summary_date' => (string) $row->summary_date,
                    'realized_pnl' => (int) $row->realized_pnl,
                    'trade_count' => (int) $row->trade_count,
                    'buy_count' => (int) $row->buy_count,
                    'sell_count' => (int) $row->sell_count,
                    'gold_opening_mg' => (int) $row->gold_opening_mg,
                    'gold_bought_mg' => (int) $row->gold_bought_mg,
                    'gold_sold_mg' => (int) $row->gold_sold_mg,
                    'gold_closing_mg' => (int) $row->gold_closing_mg,
                    'rial_opening' => (int) $row->rial_opening,
                    'rial_in' => (int) $row->rial_in,
                    'rial_out' => (int) $row->rial_out,
                    'rial_fees' => (int) $row->rial_fees,
                    'rial_closing' => (int) $row->rial_closing,
                    'closing_market_value' => (int) $row->closing_market_value,
                    'is_reconciled' => (bool) $row->is_reconciled,
                    'gold_discrepancy_mg' => (int) $row->gold_discrepancy_mg,
                    'rial_discrepancy' => (int) $row->rial_discrepancy,
                ] + $this->display($request, [
                    'realized_pnl_display' => Display::rial((int) $row->realized_pnl),
                    'gold_closing_display' => Display::grams((int) $row->gold_closing_mg),
                    'summary_date_jalali' => Display::jalali((string) $row->summary_date, false),
                ]),
                array_values($report['rows']),
            ),
            'totals' => $totals,
            'is_reconciled' => $report['is_reconciled'],
            'unreconciled_days' => array_values($report['unreconciled_days']),
        ] + $this->display($request, [
            'totals_display' => [
                'realized_pnl' => Display::rial($totals['realized_pnl']),
                'gold_bought' => Display::grams($totals['gold_bought_mg']),
                'gold_sold' => Display::grams($totals['gold_sold_mg']),
                'rial_fees' => Display::rial($totals['rial_fees']),
            ],
        ]);
    }
}
