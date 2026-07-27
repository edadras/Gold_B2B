<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use Carbon\CarbonInterface;

/**
 * Read side of the same boundary: CandleService replays prints from whoever
 * owns the trade table. Trading implements this later; until then the null
 * implementation keeps Pricing runnable on its own.
 */
interface TradePrintSourceInterface
{
    /**
     * Prints executed in [$from, $to) for one instrument, ordered by execution
     * time ascending.
     *
     * @return list<TradePrint>
     */
    public function printsBetween(int $instrumentId, CarbonInterface $from, CarbonInterface $to): array;

    /** @return list<int> instrument ids currently tradable */
    public function activeInstrumentIds(): array;
}
