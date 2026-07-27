<?php

declare(strict_types=1);

namespace App\Modules\Risk\Http\Resources;

use App\Modules\Risk\Contracts\LimitUsage;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * `GET /risk/limits` (§2.15) — every ceiling next to what today has spent.
 *
 * Utilisation is basis points, never a percentage float: §1.12's rule that the
 * numeric field is the source of truth only holds if the numeric field is
 * exact, and `48.00` is not exactly representable. The `_display` companion
 * renders "۴۸.۰۰٪" for the UI.
 *
 * @mixin LimitUsage
 */
final class LimitUsageResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LimitUsage $usage */
        $usage = $this->resource;

        return [
            'organization_id' => $usage->organizationId,
            'user_id' => $usage->userId,
            'risk_level' => $usage->riskLevel,
            'credit_score' => $usage->creditScore,
            'is_trading_allowed' => $usage->isTradingAllowed,
            'restriction_reason' => $usage->restrictionReason,

            'limits' => [
                'max_order_mg' => $usage->maxOrderMg,
                'max_daily_volume_mg' => $usage->maxDailyVolumeMg,
                'max_open_orders' => $usage->maxOpenOrders,
                'max_open_exposure_mg' => $usage->maxOpenExposureMg,
                'max_open_exposure_rial' => $usage->maxOpenExposureRial,
                'unsecured_credit_mg' => $usage->unsecuredCreditMg,
                'allowed_settlement_types' => $usage->allowedSettlementTypes,
                'max_settlement_days' => $usage->maxSettlementDays,
            ],

            'usage' => [
                'daily_volume_mg' => $usage->dailyVolumeUsedMg,
                'daily_value_rial' => $usage->dailyValueUsedRial,
                'daily_trade_count' => $usage->dailyTradeCount,
                'daily_volume_remaining_mg' => $usage->dailyVolumeRemainingMg,
                'daily_volume_utilisation_bps' => $usage->dailyVolumeUtilisationBps,
                'open_order_count' => $usage->openOrderCount,
                'open_order_remaining' => $usage->openOrderRemaining,
            ],

            'user_limit' => [
                'is_set' => $usage->userLimitSet,
                'max_order_mg' => $usage->userMaxOrderMg,
                'max_daily_volume_mg' => $usage->userMaxDailyVolumeMg,
                'requires_approval_above_mg' => $usage->userRequiresApprovalAboveMg,
                'daily_volume_mg' => $usage->userDailyVolumeUsedMg,
                'daily_volume_remaining_mg' => $usage->userDailyVolumeRemainingMg,
                'daily_volume_utilisation_bps' => $usage->userDailyVolumeUtilisationBps,
            ],
        ] + $this->display($request, [
            'max_order_display' => Display::grams($usage->maxOrderMg),
            'max_daily_volume_display' => Display::grams($usage->maxDailyVolumeMg),
            'daily_volume_display' => Display::grams($usage->dailyVolumeUsedMg),
            'daily_value_display' => Display::rial($usage->dailyValueUsedRial),
            'daily_volume_remaining_display' => Display::grams($usage->dailyVolumeRemainingMg),
            'daily_volume_utilisation_display' => Display::bps($usage->dailyVolumeUtilisationBps),
            'user_daily_volume_display' => Display::grams($usage->userDailyVolumeUsedMg),
            'user_daily_volume_remaining_display' => Display::grams($usage->userDailyVolumeRemainingMg),
        ]);
    }
}
