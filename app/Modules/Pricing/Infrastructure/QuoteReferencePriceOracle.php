<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure;

use App\Modules\Pricing\Contracts\InstrumentDirectory;
use App\Modules\Pricing\Contracts\PriceReaderInterface;
use App\Modules\Shared\Contracts\ReferencePriceOracle;

/**
 * Pricing's answer to the valuation port Shared declared for `GET /balances`.
 *
 * Uses the first active instrument's last price, falling back to its reference
 * price. Returns null when neither exists, so the balances endpoint shows "no
 * valuation" rather than a zero that reads as "your gold is worthless".
 */
final class QuoteReferencePriceOracle implements ReferencePriceOracle
{
    private bool $resolved = false;

    private ?int $cached = null;

    public function __construct(
        private readonly PriceReaderInterface $prices,
        private readonly InstrumentDirectory $instruments,
    ) {}

    public function pricePerFineGramRial(): ?int
    {
        if ($this->resolved) {
            return $this->cached;
        }

        $this->resolved = true;

        foreach ($this->instruments->activeInstrumentIds() as $instrumentId) {
            $price = $this->prices->lastPrice($instrumentId) ?? $this->prices->referencePrice($instrumentId);

            if ($price !== null && $price->rial > 0) {
                return $this->cached = $price->rial;
            }
        }

        return $this->cached = null;
    }
}
