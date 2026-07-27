<?php

declare(strict_types=1);

namespace App\Modules\Risk\Http\Resources;

use App\Modules\Risk\Infrastructure\Models\RiskProfile;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * The caller's own risk profile — `GET /risk/profile` (§2.15).
 *
 * `reviewed_by_user_id` is deliberately absent: which compliance officer looked
 * at a member is internal, and the member only needs to know that a review
 * happened and when the next one is due.
 *
 * @mixin RiskProfile
 */
final class RiskProfileResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var RiskProfile $profile */
        $profile = $this->resource;

        return [
            'organization_id' => (int) $profile->organization_id,
            'risk_level' => $profile->risk_level->value,
            'credit_score' => $profile->credit_score,
            'is_trading_allowed' => $profile->is_trading_allowed,
            'restriction_reason' => $profile->restriction_reason,
            'max_order_mg' => $profile->max_order_mg,
            'max_daily_volume_mg' => $profile->max_daily_volume_mg,
            'max_open_orders' => $profile->max_open_orders,
            'max_open_exposure_mg' => $profile->max_open_exposure_mg,
            'max_open_exposure_rial' => $profile->max_open_exposure_rial,
            'unsecured_credit_mg' => $profile->unsecured_credit_mg,
            'collateral_value_rial' => $profile->collateral_value_rial,
            'allowed_settlement_types' => array_values($profile->allowed_settlement_types),
            'max_settlement_days' => $profile->max_settlement_days,
            'reviewed_at' => Display::iso($profile->reviewed_at),
            'next_review_at' => Display::iso($profile->next_review_at),
        ] + $this->display($request, [
            'max_order_display' => Display::grams($profile->max_order_mg),
            'max_daily_volume_display' => Display::grams($profile->max_daily_volume_mg),
            'max_open_exposure_display' => Display::grams($profile->max_open_exposure_mg),
            'max_open_exposure_rial_display' => Display::rial($profile->max_open_exposure_rial),
            'unsecured_credit_display' => Display::grams($profile->unsecured_credit_mg),
            'collateral_value_display' => Display::rial($profile->collateral_value_rial),
            'reviewed_at_jalali' => Display::jalali($profile->reviewed_at),
            'next_review_at_jalali' => Display::jalali($profile->next_review_at),
        ]);
    }
}
