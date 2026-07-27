<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Contracts\InventoryRow;
use App\Modules\Reporting\Contracts\ReportingDataSource;
use App\Modules\Shared\Support\IntMath;

/**
 * «موجودی lotها» and «ارزش روز موجودی» — §15.6, both instantaneous.
 *
 * §15.8 notes that instantaneous ledger reports must come from the primary,
 * not a replica: a member checking what they hold right now is often about to
 * act on it, and replica lag would show them gold they have already sold. That
 * routing decision belongs to the data source implementation; this class just
 * documents the requirement so it is not lost.
 */
final readonly class InventoryReport
{
    public function __construct(private ReportingDataSource $data) {}

    /**
     * @return array{
     *     rows: array<int, InventoryRow>,
     *     totals: array<string, int>,
     *     by_status: array<string, array<string, int>>
     * }
     */
    public function build(int $organizationId): array
    {
        $rows = $this->data->inventory($organizationId);

        $byStatus = [];

        foreach ($rows as $row) {
            $byStatus[$row->status] ??= ['count' => 0, 'fine_mg' => 0, 'book_value_rial' => 0];
            $byStatus[$row->status]['count']++;
            $byStatus[$row->status]['fine_mg'] = IntMath::add(
                $byStatus[$row->status]['fine_mg'],
                $row->fineMg,
            );
            $byStatus[$row->status]['book_value_rial'] = IntMath::add(
                $byStatus[$row->status]['book_value_rial'],
                $row->bookValueRial,
            );
        }

        $bookValue = $this->sumOf($rows, 'bookValueRial');
        $marketValue = $this->sumOf($rows, 'marketValueRial');

        return [
            'rows' => $rows,
            'totals' => [
                'lot_count' => count($rows),
                'gross_mg' => $this->sumOf($rows, 'grossMg'),
                'fine_mg' => $this->sumOf($rows, 'fineMg'),
                'book_value_rial' => $bookValue,
                'market_value_rial' => $marketValue,
                'unrealized_rial' => IntMath::sub($marketValue, $bookValue),
            ],
            'by_status' => $byStatus,
        ];
    }

    /** @param array<int, InventoryRow> $rows */
    private function sumOf(array $rows, string $property): int
    {
        return IntMath::sum(array_map(
            static fn (InventoryRow $row): int => $row->{$property},
            $rows,
        ));
    }
}
