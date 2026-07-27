<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application;

use App\Modules\Admin\Contracts\SettlementAdminPort;
use App\Modules\Admin\Contracts\SettlementRow;
use App\Modules\Admin\Infrastructure\Tables\TableSettlementAdminAdapter;

/**
 * Read-only settlement monitor (§1.8, «SettlementMonitor»).
 *
 * The admin panel does not move a settlement's state machine. Reversing or
 * force-settling belongs to Settlement's own services, which validate the
 * transition and post the ledger legs; a status written from here would produce
 * a settlement its own code can never have produced.
 */
final class SettlementMonitorService
{
    /** The three tabs of the monitor. */
    public const TABS = [
        'open' => TableSettlementAdminAdapter::OPEN_STATUSES,
        'overdue' => ['OVERDUE'],
        'defaulted' => ['DEFAULTED'],
        'disputed' => ['DISPUTED'],
    ];

    public function __construct(private readonly SettlementAdminPort $settlements) {}

    /** @return list<SettlementRow> */
    public function tab(string $tab, int $limit = 100): array
    {
        return $this->settlements->byStatus(self::TABS[$tab] ?? self::TABS['open'], $limit);
    }

    /** @return array<string, int> */
    public function tabCounts(): array
    {
        $counts = $this->settlements->statusCounts();

        $out = [];

        foreach (self::TABS as $tab => $statuses) {
            $total = 0;

            foreach ($statuses as $status) {
                $total += $counts[$status] ?? 0;
            }

            $out[$tab] = $total;
        }

        return $out;
    }

    /**
     * @return array{settlement: SettlementRow, timeline: list<\App\Modules\Admin\Contracts\SettlementEventRow>}|null
     */
    public function detail(int $settlementId): ?array
    {
        $settlement = $this->settlements->find($settlementId);

        if ($settlement === null) {
            return null;
        }

        return [
            'settlement' => $settlement,
            'timeline' => $this->settlements->timeline($settlementId),
        ];
    }

    /** @return array{on_time: int, late: int, defaulted: int} */
    public function health(): array
    {
        return $this->settlements->healthBreakdown();
    }
}
