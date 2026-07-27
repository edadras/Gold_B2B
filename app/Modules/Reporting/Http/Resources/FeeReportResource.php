<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Contracts\FeeRow;
use App\Modules\Reporting\Domain\DateRange;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/** `GET /reports/fees` — «کارمزدهای پرداختی», grouped by category and by month. */
final class FeeReportResource extends ApiResource
{
    /** @param array{rows: array<int, FeeRow>, total_rial: int, by_category: array<string, int>, by_month: array<string, int>} $resource */
    public function __construct(mixed $resource, private readonly ?DateRange $range = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{rows: array<int, FeeRow>, total_rial: int, by_category: array<string, int>, by_month: array<string, int>} $report */
        $report = $this->resource;

        return [
            'range' => $this->range?->jsonSerialize(),
            'rows' => array_map(
                fn (FeeRow $row): array => [
                    'charged_on' => $row->chargedOn,
                    'category' => $row->category,
                    'amount_rial' => $row->amountRial,
                    'source_type' => $row->sourceType,
                    'source_id' => $row->sourceId,
                    'description' => $row->description,
                ] + $this->display($request, [
                    'amount_display' => Display::rial($row->amountRial),
                    'charged_on_jalali' => Display::jalali($row->chargedOn, false),
                ]),
                array_values($report['rows']),
            ),
            'total_rial' => $report['total_rial'],
            'by_category' => $report['by_category'],
            'by_month' => $report['by_month'],
        ] + $this->display($request, [
            'total_display' => Display::rial($report['total_rial']),
        ]);
    }
}
