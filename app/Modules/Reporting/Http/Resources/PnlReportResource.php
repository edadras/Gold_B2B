<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Contracts\PnlFacts;
use App\Modules\Reporting\Domain\DateRange;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * `GET /reports/pnl` — §9.4's statement, with §9.5's informational block kept
 * strictly below the line.
 *
 * The separation is structural, not cosmetic: `statement` is the accounting
 * result (realised only) and `informational` holds the mark-to-market figures,
 * which are an opinion about a price and must never be added into the
 * statement. Each informational line carries its own `available` flag, because
 * "no market price could be read" and "the unrealised gain is zero" are very
 * different statements.
 */
final class PnlReportResource extends ApiResource
{
    /** @param array{facts: PnlFacts, statement: array<int, array<string, mixed>>, informational: array<int, array<string, mixed>>} $resource */
    public function __construct(mixed $resource, private readonly ?DateRange $range = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{facts: PnlFacts, statement: array<int, array<string, mixed>>, informational: array<int, array<string, mixed>>} $report */
        $report = $this->resource;

        $facts = $report['facts'];

        return [
            'range' => $this->range?->jsonSerialize(),
            'sales_revenue' => $facts->salesRevenue,
            'cost_of_goods_sold' => $facts->costOfGoodsSold,
            'gross_profit' => $facts->grossProfit(),
            'operating_expenses' => $facts->operatingExpenses,
            'total_expenses' => $facts->totalExpenses(),
            'net_operating_profit' => $facts->netOperatingProfit(),
            'statement' => array_map(
                fn (array $line): array => [
                    'label' => (string) $line['label'],
                    'rial' => (int) $line['rial'],
                    'is_total' => (bool) $line['is_total'],
                ] + $this->display($request, [
                    'rial_display' => Display::rial((int) $line['rial']),
                ]),
                array_values($report['statement']),
            ),
            // «اطلاعاتی (غیر از دفتر)» — never part of the accounting result.
            'informational' => array_map(
                fn (array $line): array => [
                    'label' => (string) $line['label'],
                    'rial' => (int) $line['rial'],
                    'available' => (bool) $line['available'],
                ] + $this->display($request, [
                    'rial_display' => $line['available'] ? Display::rial((int) $line['rial']) : null,
                ]),
                array_values($report['informational']),
            ),
            'informational_only_note' => 'ارقام بخش اطلاعاتی تحقق‌نیافته‌اند و در دفاتر ثبت نمی‌شوند.',
        ] + $this->display($request, [
            'net_operating_profit_display' => Display::rial($facts->netOperatingProfit()),
            'gross_profit_display' => Display::rial($facts->grossProfit()),
        ]);
    }
}
