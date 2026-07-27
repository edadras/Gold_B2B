<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Contracts\FeeRow;
use App\Modules\Reporting\Contracts\ReportingDataSource;
use App\Modules\Reporting\Domain\DateRange;
use App\Modules\Shared\Support\IntMath;

/**
 * «کارمزدهای پرداختی» — §15.6, monthly.
 *
 * Grouped by category and by month, because the question a member actually asks
 * of this report is "what am I paying, and is it going up".
 */
final readonly class FeeReport
{
    public function __construct(private ReportingDataSource $data) {}

    /**
     * @return array{
     *     rows: array<int, FeeRow>,
     *     total_rial: int,
     *     by_category: array<string, int>,
     *     by_month: array<string, int>
     * }
     */
    public function build(int $organizationId, DateRange $range): array
    {
        $rows = $this->data->fees($organizationId, $range);

        $byCategory = [];
        $byMonth = [];

        foreach ($rows as $row) {
            $byCategory[$row->category] = IntMath::add(
                $byCategory[$row->category] ?? 0,
                $row->amountRial,
            );

            $month = substr($row->chargedOn, 0, 7);

            $byMonth[$month] = IntMath::add($byMonth[$month] ?? 0, $row->amountRial);
        }

        ksort($byMonth);

        return [
            'rows' => $rows,
            'total_rial' => IntMath::sum(array_values($byCategory)),
            'by_category' => $byCategory,
            'by_month' => $byMonth,
        ];
    }
}
