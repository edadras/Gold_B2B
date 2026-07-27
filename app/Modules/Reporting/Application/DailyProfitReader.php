<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Domain\DateRange;
use App\Modules\Reporting\Infrastructure\Models\DailyOrgSummaryModel;
use App\Modules\Shared\Support\IntMath;

/**
 * Reads back the nightly rollup DailySummaryBuilder writes — `GET
 * /reports/daily-profit` (docs/05-api/02-endpoints.md §2.13).
 *
 * A reader rather than a call into DailySummaryBuilder, because the builder
 * WRITES: building a range on demand would let an HTTP GET rewrite historical
 * summary rows, and a request that arrives before the market closes would
 * overwrite yesterday's finished figures with today's partial ones. The daily
 * profit endpoint reports what the nightly job computed; a date with no row is
 * simply absent from the series, which is the honest answer.
 *
 * `is_reconciled` is carried through per row and summarised for the range,
 * because §15.9 makes an unreconciled day a red flag and hiding it behind a
 * total is exactly the failure the flag exists to prevent.
 */
final readonly class DailyProfitReader
{
    /**
     * @return array{
     *     rows: array<int, DailyOrgSummaryModel>,
     *     totals: array<string, int>,
     *     is_reconciled: bool,
     *     unreconciled_days: array<int, string>
     * }
     */
    public function forRange(int $organizationId, DateRange $range): array
    {
        /** @var array<int, DailyOrgSummaryModel> $rows */
        $rows = DailyOrgSummaryModel::query()
            ->where('organization_id', $organizationId)
            ->whereBetween('summary_date', [$range->from, $range->to])
            ->orderBy('summary_date')
            ->get()
            ->all();

        $unreconciled = [];

        $totals = [
            'day_count' => count($rows),
            'realized_pnl' => 0,
            'trade_count' => 0,
            'buy_count' => 0,
            'sell_count' => 0,
            'gold_bought_mg' => 0,
            'gold_sold_mg' => 0,
            'rial_in' => 0,
            'rial_out' => 0,
            'rial_fees' => 0,
        ];

        foreach ($rows as $row) {
            $totals['realized_pnl'] = IntMath::add($totals['realized_pnl'], (int) $row->realized_pnl);
            $totals['trade_count'] = IntMath::add($totals['trade_count'], (int) $row->trade_count);
            $totals['buy_count'] = IntMath::add($totals['buy_count'], (int) $row->buy_count);
            $totals['sell_count'] = IntMath::add($totals['sell_count'], (int) $row->sell_count);
            $totals['gold_bought_mg'] = IntMath::add($totals['gold_bought_mg'], (int) $row->gold_bought_mg);
            $totals['gold_sold_mg'] = IntMath::add($totals['gold_sold_mg'], (int) $row->gold_sold_mg);
            $totals['rial_in'] = IntMath::add($totals['rial_in'], (int) $row->rial_in);
            $totals['rial_out'] = IntMath::add($totals['rial_out'], (int) $row->rial_out);
            $totals['rial_fees'] = IntMath::add($totals['rial_fees'], (int) $row->rial_fees);

            if (! $row->is_reconciled) {
                $unreconciled[] = (string) $row->summary_date;
            }
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'is_reconciled' => $unreconciled === [],
            'unreconciled_days' => $unreconciled,
        ];
    }
}
