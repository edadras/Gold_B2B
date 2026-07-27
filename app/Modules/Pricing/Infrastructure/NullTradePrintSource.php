<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure;

use App\Modules\Pricing\Contracts\TradePrintSourceInterface;
use Carbon\CarbonInterface;

/**
 * Default binding so Pricing boots and its console commands run before the
 * Trading module exists. Trading replaces this binding with a real reader.
 */
final class NullTradePrintSource implements TradePrintSourceInterface
{
    public function printsBetween(int $instrumentId, CarbonInterface $from, CarbonInterface $to): array
    {
        return [];
    }

    public function activeInstrumentIds(): array
    {
        return [];
    }
}
