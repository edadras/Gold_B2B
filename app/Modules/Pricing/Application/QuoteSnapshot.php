<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Domain\Spread;
use Carbon\CarbonImmutable;

/**
 * Read model for §7.10. Every field is a plain scalar so it can cross a module
 * or an API boundary unchanged.
 */
final readonly class QuoteSnapshot
{
    public function __construct(
        public int $instrumentId,
        public ?int $bestBid,
        public ?int $bestBidQtyMg,
        public ?int $bestAsk,
        public ?int $bestAskQtyMg,
        public ?int $lastPrice,
        public ?int $lastQtyMg,
        public ?CarbonImmutable $lastAt,
        public bool $lastIsStale,
        public ?int $dayOpen,
        public ?int $dayHigh,
        public ?int $dayLow,
        public int $dayVolumeMg,
        public ?int $dayVwap,
        public int $dayTradeCount,
        public ?Spread $spread,
    ) {}

    public function mid(): ?int
    {
        return $this->spread?->mid;
    }

    /** LAST − OPEN, the "change" column of §7.6. */
    public function changeRial(): ?int
    {
        if ($this->lastPrice === null || $this->dayOpen === null) {
            return null;
        }

        return $this->lastPrice - $this->dayOpen;
    }

    public function changeBps(): ?int
    {
        $change = $this->changeRial();

        if ($change === null || $this->dayOpen === null || $this->dayOpen === 0) {
            return null;
        }

        return intdiv($change * 10_000, $this->dayOpen);
    }
}
