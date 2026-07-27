<?php

declare(strict_types=1);

namespace App\Modules\Risk\Aml\Rules;

use App\Modules\Shared\Support\IntMath;

/**
 * "Same size, near enough" — the predicate PAT-01, PAT-02 and PAT-03 all need.
 */
trait WeightComparison
{
    protected function weightsAreSimilar(int $a, int $b, int $toleranceBps): bool
    {
        if ($a <= 0 || $b <= 0) {
            return false;
        }

        $difference = abs($a - $b);

        return IntMath::mulDivFloor($difference, 10_000, max($a, $b)) <= $toleranceBps;
    }

    /** @param list<int> $weights */
    protected function allWeightsSimilar(array $weights, int $toleranceBps): bool
    {
        if (count($weights) < 2) {
            return false;
        }

        $min = min($weights);
        $max = max($weights);

        return $this->weightsAreSimilar($min, $max, $toleranceBps);
    }
}
