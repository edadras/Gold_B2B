<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

/**
 * Settlement\Contracts\SettlementReaderInterface answers per-trade and
 * per-organisation questions; the monitor needs platform-wide slices by status,
 * which it does not expose.
 */
interface SettlementAdminPort
{
    /**
     * @param  list<string>  $statuses
     * @return list<SettlementRow>
     */
    public function byStatus(array $statuses, int $limit = 100): array;

    /** @return array<string, int> status => count */
    public function statusCounts(): array;

    public function find(int $settlementId): ?SettlementRow;

    /** @return list<SettlementEventRow> oldest first */
    public function timeline(int $settlementId): array;

    /** @return array{on_time: int, late: int, defaulted: int} */
    public function healthBreakdown(): array;
}
