<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure;

use App\Modules\Pricing\Contracts\TradePrint;
use App\Modules\Pricing\Contracts\TradePrintSourceInterface;
use Carbon\CarbonInterface;

/**
 * In-process implementation used by tests and by the candle backfill tooling.
 * Keeping it in Infrastructure rather than Tests lets Trading reuse it while
 * its own reader is being written.
 */
final class InMemoryTradePrintSource implements TradePrintSourceInterface
{
    /** @var list<TradePrint> */
    private array $prints = [];

    /** @param list<TradePrint> $prints */
    public function __construct(array $prints = [])
    {
        foreach ($prints as $print) {
            $this->add($print);
        }
    }

    public function add(TradePrint $print): self
    {
        $this->prints[] = $print;

        return $this;
    }

    public function printsBetween(int $instrumentId, CarbonInterface $from, CarbonInterface $to): array
    {
        $matched = array_filter(
            $this->prints,
            static fn (TradePrint $print): bool => $print->instrumentId === $instrumentId
                && $print->executedAt->greaterThanOrEqualTo($from)
                && $print->executedAt->lessThan($to),
        );

        $matched = array_values($matched);

        usort(
            $matched,
            static fn (TradePrint $a, TradePrint $b): int => $a->executedAt->getTimestamp() <=> $b->executedAt->getTimestamp(),
        );

        return $matched;
    }

    public function activeInstrumentIds(): array
    {
        return array_values(array_unique(
            array_map(static fn (TradePrint $print): int => $print->instrumentId, $this->prints)
        ));
    }
}
