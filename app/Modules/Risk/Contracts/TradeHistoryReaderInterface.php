<?php

declare(strict_types=1);

namespace App\Modules\Risk\Contracts;

use Carbon\CarbonInterface;

/**
 * How the AML rules see trade history. Trading implements this once it owns a
 * trades table; until then NullTradeHistoryReader is bound, which makes every
 * history-dependent rule return PASS instead of exploding.
 */
interface TradeHistoryReaderInterface
{
    /**
     * Every trade the organization took part in, on either side, since $since.
     *
     * @return list<TradeRecord>
     */
    public function tradesFor(int $organizationId, CarbonInterface $since): array;

    /**
     * Trades between two organizations in either direction since $since.
     *
     * @return list<TradeRecord>
     */
    public function tradesBetween(int $organizationId, int $counterpartyOrgId, CarbonInterface $since): array;

    /**
     * Every trade on the platform since $since — the edge list for cycle
     * detection (§12.8). Implementations must bound this by time, not return
     * the whole table.
     *
     * @return list<TradeRecord>
     */
    public function recentTrades(CarbonInterface $since): array;

    /** Fine milligrams traded by the organization on a given calendar day. */
    public function dailyVolumeMg(int $organizationId, CarbonInterface $day): int;

    /**
     * Mean daily volume over the preceding $days days, excluding today. Used as
     * the per-member baseline of §12.9; returns 0 when there is no history.
     */
    public function averageDailyVolumeMg(int $organizationId, int $days): int;
}
