<?php

declare(strict_types=1);

namespace App\Modules\Risk\Http\Resources;

use App\Modules\Risk\Contracts\ExposureOverview;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * `GET /risk/exposure` (§2.15) — F18 next to F20.
 *
 * `coverage_ratio_bps` is null, not zero, when there is nothing to cover: a
 * client that rendered a null as 0% would show a healthy member as being in
 * partial liquidation.
 *
 * @mixin ExposureOverview
 */
final class ExposureResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ExposureOverview $exposure */
        $exposure = $this->resource;

        return [
            'organization_id' => $exposure->organizationId,

            'exposure' => [
                'gold_mg' => $exposure->goldExposureMg,
                'rial' => $exposure->rialExposure,
                'settlement_gold_mg' => $exposure->settlementGoldMg,
                'open_order_gold_mg' => $exposure->openOrderGoldMg,
                'settlement_rial' => $exposure->settlementRial,
                'open_order_rial' => $exposure->openOrderRial,
                'open_order_count' => $exposure->openOrderCount,
            ],

            'limits' => [
                'max_open_exposure_mg' => $exposure->maxOpenExposureMg,
                'max_open_exposure_rial' => $exposure->maxOpenExposureRial,
                'gold_utilisation_bps' => $exposure->goldExposureUtilisationBps,
                'rial_utilisation_bps' => $exposure->rialExposureUtilisationBps,
            ],

            'coverage' => [
                'collateral_value_rial' => $exposure->collateralValueRial,
                'exposure_value_rial' => $exposure->exposureValueRial,
                'ratio_bps' => $exposure->coverageRatioBps,
                'status' => $exposure->coverageStatus,
                'requires_margin_call' => $exposure->requiresMarginCall,
                'blocks_new_trades' => $exposure->blocksNewTrades,
                'shortfall_to_healthy_rial' => $exposure->shortfallToHealthyRial,
                'top_up_deadline_hours' => $exposure->topUpDeadlineHours,
            ],
        ] + $this->display($request, [
            'gold_exposure_display' => Display::grams($exposure->goldExposureMg),
            'rial_exposure_display' => Display::rial($exposure->rialExposure),
            'collateral_value_display' => Display::rial($exposure->collateralValueRial),
            'exposure_value_display' => Display::rial($exposure->exposureValueRial),
            'coverage_ratio_display' => Display::bps($exposure->coverageRatioBps),
            'shortfall_to_healthy_display' => Display::rial($exposure->shortfallToHealthyRial),
        ]);
    }
}
