<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Contracts\TradeRow;
use App\Modules\Reporting\Domain\DateRange;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * `GET /reports/trades` — the list, its totals and the per-venue split.
 *
 * Weights are fine milligrams and money is rial, both integers; the price is
 * rial per fine GRAM, which is the unit the whole platform quotes in.
 */
final class TradeReportResource extends ApiResource
{
    /** @param array{rows: array<int, TradeRow>, totals: array<string, int>, by_venue: array<string, array<string, int>>} $resource */
    public function __construct(mixed $resource, private readonly ?DateRange $range = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{rows: array<int, TradeRow>, totals: array<string, int>, by_venue: array<string, array<string, int>>} $report */
        $report = $this->resource;

        $rows = array_map(
            fn (TradeRow $row): array => [
                'trade_id' => $row->tradeId,
                'executed_on' => $row->executedOn,
                'side' => $row->side,
                'venue' => $row->venue,
                'fine_mg' => $row->fineMg,
                'purity_x10k' => $row->purityX10k,
                'price_per_fine_gram' => $row->pricePerFineGram,
                'gross_rial' => $row->grossRial,
                'fee_rial' => $row->feeRial,
                'net_rial' => $row->netRial(),
                'counterparty_org_id' => $row->counterpartyOrgId,
                'counterparty_name' => $row->counterpartyName,
            ] + $this->display($request, [
                'fine_display' => Display::grams($row->fineMg),
                'purity_display' => Display::purity($row->purityX10k),
                'gross_display' => Display::rial($row->grossRial),
                'fee_display' => Display::rial($row->feeRial),
                'executed_on_jalali' => Display::jalali($row->executedOn, false),
            ]),
            array_values($report['rows']),
        );

        $totals = $report['totals'];

        return [
            'range' => $this->range?->jsonSerialize(),
            'rows' => $rows,
            'totals' => $totals,
            'by_venue' => $report['by_venue'],
        ] + $this->display($request, [
            'totals_display' => [
                'bought_fine' => Display::grams($totals['bought_fine_mg']),
                'sold_fine' => Display::grams($totals['sold_fine_mg']),
                'bought_rial' => Display::rial($totals['bought_rial']),
                'sold_rial' => Display::rial($totals['sold_rial']),
                'fees_rial' => Display::rial($totals['fees_rial']),
            ],
        ]);
    }
}
