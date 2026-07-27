<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Contracts\ReportingDataSource;
use App\Modules\Reporting\Contracts\TradeRow;
use App\Modules\Reporting\Domain\DateRange;
use App\Modules\Shared\Support\IntMath;

/**
 * «فهرست معاملات» — §15.6.
 *
 * A list plus its totals. The totals are computed with IntMath rather than
 * array_sum: these are rial figures in the tens of billions and a member's
 * month can run into the trillions, which is comfortably inside a 64-bit
 * integer but not inside anything that silently promotes to float.
 */
final readonly class TradeReport
{
    public function __construct(private ReportingDataSource $data) {}

    /**
     * @return array{
     *     rows: array<int, TradeRow>,
     *     totals: array<string, int>,
     *     by_venue: array<string, array<string, int>>
     * }
     */
    public function build(int $organizationId, DateRange $range): array
    {
        $rows = $this->data->trades($organizationId, $range);

        $buys = array_values(array_filter($rows, static fn (TradeRow $r): bool => $r->isBuy()));
        $sells = array_values(array_filter($rows, static fn (TradeRow $r): bool => ! $r->isBuy()));

        return [
            'rows' => $rows,
            'totals' => [
                'trade_count' => count($rows),
                'buy_count' => count($buys),
                'sell_count' => count($sells),
                'bought_fine_mg' => $this->sumOf($buys, 'fineMg'),
                'sold_fine_mg' => $this->sumOf($sells, 'fineMg'),
                'bought_rial' => $this->sumOf($buys, 'grossRial'),
                'sold_rial' => $this->sumOf($sells, 'grossRial'),
                'fees_rial' => $this->sumOf($rows, 'feeRial'),
            ],
            'by_venue' => $this->groupByVenue($rows),
        ];
    }

    /**
     * The §9.4 gold-flow breakdown — «از Order Book / از OTC / از RFQ» — which
     * the flow report shows as sub-lines under purchases and sales.
     *
     * @param  array<int, TradeRow>  $rows
     * @return array<string, array<string, int>>
     */
    private function groupByVenue(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $venue = $row->venue;

            $grouped[$venue] ??= [
                'buy_fine_mg' => 0,
                'sell_fine_mg' => 0,
                'buy_rial' => 0,
                'sell_rial' => 0,
                'count' => 0,
            ];

            $weightKey = $row->isBuy() ? 'buy_fine_mg' : 'sell_fine_mg';
            $rialKey = $row->isBuy() ? 'buy_rial' : 'sell_rial';

            $grouped[$venue][$weightKey] = IntMath::add($grouped[$venue][$weightKey], $row->fineMg);
            $grouped[$venue][$rialKey] = IntMath::add($grouped[$venue][$rialKey], $row->grossRial);
            $grouped[$venue]['count']++;
        }

        return $grouped;
    }

    /** @param array<int, TradeRow> $rows */
    private function sumOf(array $rows, string $property): int
    {
        return IntMath::sum(array_map(
            static fn (TradeRow $row): int => $row->{$property},
            $rows,
        ));
    }
}
