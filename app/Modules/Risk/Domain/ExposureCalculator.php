<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain;

use App\Modules\Shared\Support\IntMath;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Shared\ValueObjects\Weight;

/**
 * F18 — open exposure.
 *
 *   gold_exposure_mg = Σ settlements.fine_weight_mg
 *                        (deliverer = org, status not in SETTLED, COMPLETED,
 *                         CANCELLED, REVERSED)
 *                    + Σ (orders.quantity_mg − orders.filled_mg)
 *                        (org's OPEN / PARTIALLY_FILLED sells)
 *
 * The rial leg mirrors it: unpaid obligations plus rial reserved by open buys.
 *
 * The calculator is pure; the two sums come from
 * TradingExposureReaderInterface, which Trading and Settlement implement.
 */
final class ExposureCalculator
{
    public function combine(
        int $settlementGoldMg,
        int $openOrderGoldMg,
        int $settlementRial = 0,
        int $openOrderRial = 0,
    ): Exposure {
        return new Exposure(
            goldMg: IntMath::add($settlementGoldMg, $openOrderGoldMg),
            rial: IntMath::add($settlementRial, $openOrderRial),
            settlementGoldMg: $settlementGoldMg,
            openOrderGoldMg: $openOrderGoldMg,
            settlementRial: $settlementRial,
            openOrderRial: $openOrderRial,
        );
    }

    /**
     * Total exposure valued in rial, for the collateral coverage ratio (F20).
     * The gold leg is marked to the supplied price: floor(mg × price / 1000).
     */
    public function valueInRial(Exposure $exposure, PricePerFineGram $goldPrice): int
    {
        $goldValue = IntMath::mulDivFloor($exposure->goldMg, $goldPrice->rial, Weight::MG_PER_GRAM);

        return IntMath::add($goldValue, $exposure->rial);
    }

    /**
     * Utilisation of a ceiling in basis points, for the admin risk panel of
     * §11.9 ("2,400 g of 5,000 → 48%"). Null when no ceiling is set.
     */
    public function utilisationBps(int $used, int $limit): ?int
    {
        if ($limit <= 0) {
            return null;
        }

        return IntMath::mulDivFloor($used, 10_000, $limit);
    }
}
