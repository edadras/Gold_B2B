<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain;

use App\Modules\Shared\Support\IntMath;

/**
 * F20 — coverage_ratio_bps = floor(collateral_value × 10000 / open_exposure).
 *
 * Bands (§11.7):
 *   ≥ 15000  healthy
 *   12000–14999  warning
 *   11000–11999  margin call, four hours to top up
 *   < 11000  stop new trades and liquidate partially
 */
final class CollateralCoverage
{
    private const BPS_SCALE = 10_000;

    public function evaluate(int $collateralValueRial, int $openExposureRial): CoverageAssessment
    {
        if ($openExposureRial <= 0) {
            // Nothing at risk: coverage is undefined, not zero.
            return new CoverageAssessment(
                collateralValueRial: $collateralValueRial,
                exposureValueRial: max($openExposureRial, 0),
                ratioBps: null,
                status: CoverageStatus::HEALTHY,
            );
        }

        $ratio = IntMath::mulDivFloor(max($collateralValueRial, 0), self::BPS_SCALE, $openExposureRial);

        return new CoverageAssessment(
            collateralValueRial: $collateralValueRial,
            exposureValueRial: $openExposureRial,
            ratioBps: $ratio,
            status: CoverageStatus::fromRatioBps($ratio),
        );
    }

    /**
     * §11.6 — accepted value of one pledge: nominal × acceptance factor.
     */
    public function acceptedValue(int $nominalValueRial, int $acceptanceFactorBps): int
    {
        return IntMath::mulDivFloor(max($nominalValueRial, 0), $acceptanceFactorBps, self::BPS_SCALE);
    }

    /**
     * §11.6 — total credit ceiling in fine milligrams:
     * unsecured credit + Σ(collateral gold × acceptance factor).
     *
     * @param  list<array{0:int,1:int}>  $pledges  [fineWeightMg, acceptanceFactorBps]
     */
    public function creditCeilingMg(int $unsecuredCreditMg, array $pledges): int
    {
        $total = $unsecuredCreditMg;

        foreach ($pledges as [$fineWeightMg, $factorBps]) {
            $total = IntMath::add(
                $total,
                IntMath::mulDivFloor(max($fineWeightMg, 0), $factorBps, self::BPS_SCALE),
            );
        }

        return $total;
    }
}
