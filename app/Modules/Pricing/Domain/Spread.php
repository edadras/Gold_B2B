<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use App\Modules\Shared\Support\IntMath;

/**
 * F23 — bid/ask spread.
 *
 *   spread_rial = best_ask − best_bid
 *   mid_price   = floor((best_ask + best_bid) / 2)
 *   spread_bps  = floor(spread_rial × 10000 / mid_price)
 */
final readonly class Spread
{
    private function __construct(
        public int $bestBid,
        public int $bestAsk,
        public int $rial,
        public int $mid,
        public ?int $bps,
    ) {}

    public static function between(int $bestBid, int $bestAsk): self
    {
        $rial = IntMath::sub($bestAsk, $bestBid);
        $mid = intdiv(IntMath::add($bestAsk, $bestBid), 2);
        $bps = $mid === 0 ? null : IntMath::mulDivFloor($rial, 10_000, $mid);

        return new self($bestBid, $bestAsk, $rial, $mid, $bps);
    }

    /** A crossed book (ask below bid) is always a bug or a stale quote. */
    public function isCrossed(): bool
    {
        return $this->rial < 0;
    }
}
