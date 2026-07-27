<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Contracts\InventoryRow;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * `GET /reports/inventory` — the lots held right now.
 *
 * Instantaneous, so it takes no date range: §15.8 requires this one to be read
 * from the primary rather than a replica, because a member checking what they
 * hold is usually about to act on it and replica lag would show them gold they
 * have already sold.
 */
final class InventoryReportResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{rows: array<int, InventoryRow>, totals: array<string, int>, by_status: array<string, array<string, int>>} $report */
        $report = $this->resource;

        $totals = $report['totals'];

        return [
            'as_of' => Display::iso(now()),
            'rows' => array_map(
                fn (InventoryRow $row): array => [
                    'lot_code' => $row->lotCode,
                    'gross_mg' => $row->grossMg,
                    'fine_mg' => $row->fineMg,
                    'purity_x10k' => $row->purityX10k,
                    'status' => $row->status,
                    'location' => $row->location,
                    'book_value_rial' => $row->bookValueRial,
                    'market_value_rial' => $row->marketValueRial,
                    'unrealized_rial' => $row->unrealizedRial(),
                    'acquired_on' => $row->acquiredOn,
                ] + $this->display($request, [
                    'gross_display' => Display::grams($row->grossMg),
                    'fine_display' => Display::grams($row->fineMg),
                    'purity_display' => Display::purity($row->purityX10k),
                    'book_value_display' => Display::rial($row->bookValueRial),
                    'market_value_display' => Display::rial($row->marketValueRial),
                ]),
                array_values($report['rows']),
            ),
            'totals' => $totals,
            'by_status' => $report['by_status'],
        ] + $this->display($request, [
            'totals_display' => [
                'fine' => Display::grams($totals['fine_mg']),
                'book_value' => Display::rial($totals['book_value_rial']),
                'market_value' => Display::rial($totals['market_value_rial']),
                'unrealized' => Display::rial($totals['unrealized_rial']),
            ],
            'as_of_jalali' => Display::jalali(now()),
        ]);
    }
}
