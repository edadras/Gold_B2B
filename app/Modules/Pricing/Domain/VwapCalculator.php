<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use App\Modules\Shared\Support\IntMath;

/**
 * F22 — volume weighted average price.
 *
 *   VWAP = floor(Σ (price_i × qty_i) / Σ qty_i)
 *
 * Unlike LAST, VWAP includes OTC prints (docs/03-domain/07-pricing.md §7.6).
 */
final class VwapCalculator
{
    /**
     * @param  list<array{0:int,1:int}>  $priceQuantityPairs  [pricePerFineGramRial, fineWeightMg]
     */
    public function vwap(array $priceQuantityPairs): ?int
    {
        $numerator = 0;
        $denominator = 0;

        foreach ($priceQuantityPairs as [$price, $quantityMg]) {
            $numerator = IntMath::add($numerator, IntMath::mul($price, $quantityMg));
            $denominator = IntMath::add($denominator, $quantityMg);
        }

        return $this->fromAccumulators($numerator, $denominator);
    }

    /**
     * Incremental form used by QuoteService, which keeps the running numerator
     * on market_quotes so no trade replay is needed.
     */
    public function fromAccumulators(int $numerator, int $totalQuantityMg): ?int
    {
        if ($totalQuantityMg <= 0) {
            return null;
        }

        return IntMath::mulDivFloor($numerator, 1, $totalQuantityMg);
    }

    public function contribution(int $pricePerFineGramRial, int $fineWeightMg): int
    {
        return IntMath::mul($pricePerFineGramRial, $fineWeightMg);
    }
}
