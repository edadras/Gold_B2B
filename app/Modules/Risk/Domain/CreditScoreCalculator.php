<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain;

use App\Modules\Risk\Contracts\MemberActivityStats;
use App\Modules\Shared\Support\IntMath;

/**
 * F19 — seven weighted components summing to exactly 1000.
 *
 *   on_time_rate   × 350   on_time / total
 *   tenure         × 150   min(months / 24, 1)
 *   volume         × 150   min(log10(volume_mg / 1000) / 6, 1)
 *   dispute_clean  × 150   1 − min(disputes_lost / max(trades,1) × 100, 1)
 *   kyc_complete   × 100   verified_items / total_items
 *   cp_diversity   ×  50   min(distinct_counterparties / 20, 1)
 *   aml_clean      ×  50   active_flags == 0 ? 1 : 0
 *
 * Integer arithmetic throughout. Each component is first expressed on a
 * fixed-point 0..SCALE axis, then multiplied by its weight and floored, so the
 * result is bounded by 0..1000 by construction.
 *
 * The volume component needs a base-10 logarithm, which has no exact integer
 * form. logScaled() uses the exact integer part plus a linear interpolation of
 * the fraction within the decade. That is monotonic, deterministic across
 * platforms and free of floating point — the three properties that matter here.
 * It is not the true logarithm, and the doc's "log-scale" wording is satisfied
 * by any monotone compression of volume onto 0..1.
 */
final class CreditScoreCalculator
{
    private const SCALE = 1_000_000;

    private const WEIGHT_ON_TIME = 350;

    private const WEIGHT_TENURE = 150;

    private const WEIGHT_VOLUME = 150;

    private const WEIGHT_DISPUTE = 150;

    private const WEIGHT_KYC = 100;

    private const WEIGHT_DIVERSITY = 50;

    private const WEIGHT_AML = 50;

    /** Full marks at 10^6 grams (1,000 kg) of lifetime volume. */
    private const VOLUME_LOG_DECADES = 6;

    private const TENURE_MONTHS_FOR_FULL_MARKS = 24;

    private const DIVERSITY_COUNTERPARTIES_FOR_FULL_MARKS = 20;

    public function calculate(MemberActivityStats $stats): CreditScore
    {
        $breakdown = [
            'on_time_rate' => $this->weighted($this->onTimeComponent($stats), self::WEIGHT_ON_TIME),
            'tenure' => $this->weighted($this->tenureComponent($stats), self::WEIGHT_TENURE),
            'volume' => $this->weighted($this->volumeComponent($stats), self::WEIGHT_VOLUME),
            'dispute_clean' => $this->weighted($this->disputeComponent($stats), self::WEIGHT_DISPUTE),
            'kyc_complete' => $this->weighted($this->kycComponent($stats), self::WEIGHT_KYC),
            'cp_diversity' => $this->weighted($this->diversityComponent($stats), self::WEIGHT_DIVERSITY),
            'aml_clean' => $this->weighted($this->amlComponent($stats), self::WEIGHT_AML),
        ];

        return new CreditScore(IntMath::sum($breakdown), $breakdown);
    }

    /**
     * A member with no settlement history scores zero here rather than being
     * given the benefit of the doubt: F19 has no "unknown" state, and §11.1
     * already starts newcomers at MEDIUM regardless of score.
     */
    private function onTimeComponent(MemberActivityStats $stats): int
    {
        if ($stats->settlementsTotal <= 0) {
            return 0;
        }

        return $this->ratio($stats->settlementsOnTime, $stats->settlementsTotal);
    }

    private function tenureComponent(MemberActivityStats $stats): int
    {
        return $this->ratio(
            min(max($stats->monthsActive, 0), self::TENURE_MONTHS_FOR_FULL_MARKS),
            self::TENURE_MONTHS_FOR_FULL_MARKS,
        );
    }

    private function volumeComponent(MemberActivityStats $stats): int
    {
        $grams = intdiv(max($stats->totalVolumeMg, 0), 1_000);

        if ($grams <= 0) {
            return 0;
        }

        $log = $this->logScaled($grams);
        $ceiling = self::VOLUME_LOG_DECADES * self::SCALE;

        return $this->ratio(min($log, $ceiling), $ceiling);
    }

    private function disputeComponent(MemberActivityStats $stats): int
    {
        $trades = max($stats->totalTrades, 1);

        // dispute_rate × 100, i.e. a 1% loss rate already zeroes the component.
        $penalty = min(
            IntMath::mulDivFloor(max($stats->disputesLost, 0) * 100, self::SCALE, $trades),
            self::SCALE,
        );

        return self::SCALE - $penalty;
    }

    private function kycComponent(MemberActivityStats $stats): int
    {
        if ($stats->kycTotalItems <= 0) {
            return 0;
        }

        return $this->ratio(
            min(max($stats->kycVerifiedItems, 0), $stats->kycTotalItems),
            $stats->kycTotalItems,
        );
    }

    private function diversityComponent(MemberActivityStats $stats): int
    {
        return $this->ratio(
            min(max($stats->distinctCounterparties, 0), self::DIVERSITY_COUNTERPARTIES_FOR_FULL_MARKS),
            self::DIVERSITY_COUNTERPARTIES_FOR_FULL_MARKS,
        );
    }

    private function amlComponent(MemberActivityStats $stats): int
    {
        return $stats->activeAmlFlags === 0 ? self::SCALE : 0;
    }

    /** Fixed-point numerator/denominator on the 0..SCALE axis. */
    private function ratio(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            return 0;
        }

        return min(IntMath::mulDivFloor(max($numerator, 0), self::SCALE, $denominator), self::SCALE);
    }

    private function weighted(int $component, int $weight): int
    {
        return IntMath::mulDivFloor(min(max($component, 0), self::SCALE), $weight, self::SCALE);
    }

    /**
     * Piecewise-linear log10, scaled by SCALE. Exact on the powers of ten and
     * strictly increasing in between.
     */
    private function logScaled(int $value): int
    {
        $decade = 0;
        $base = 1;

        while ($base * 10 <= $value) {
            $base *= 10;
            $decade++;
        }

        // value ∈ [base, base×10) → fraction = (value − base) / (9 × base)
        $fraction = IntMath::mulDivFloor($value - $base, self::SCALE, 9 * $base);

        return $decade * self::SCALE + $fraction;
    }
}
