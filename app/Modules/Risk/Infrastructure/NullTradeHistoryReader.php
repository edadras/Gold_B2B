<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure;

use App\Modules\Risk\Contracts\TradeHistoryReaderInterface;
use Carbon\CarbonInterface;

/**
 * Default binding so the AML engine runs before Trading exists.
 *
 * Every history-dependent rule sees an empty world and returns PASS. That is
 * the right default: a rule that cannot see the data must not invent a flag.
 * CPT-04 (self-trade) and the parameter-only rules still work.
 */
final class NullTradeHistoryReader implements TradeHistoryReaderInterface
{
    public function tradesFor(int $organizationId, CarbonInterface $since): array
    {
        return [];
    }

    public function tradesBetween(int $organizationId, int $counterpartyOrgId, CarbonInterface $since): array
    {
        return [];
    }

    public function recentTrades(CarbonInterface $since): array
    {
        return [];
    }

    public function dailyVolumeMg(int $organizationId, CarbonInterface $day): int
    {
        return 0;
    }

    public function averageDailyVolumeMg(int $organizationId, int $days): int
    {
        return 0;
    }
}
