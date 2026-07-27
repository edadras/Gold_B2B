<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application;

use App\Modules\Risk\Contracts\ExposureOverview;
use App\Modules\Risk\Contracts\LimitUsage;
use App\Modules\Risk\Contracts\TradingExposureReaderInterface;
use App\Modules\Risk\Domain\ExposureCalculator;
use App\Modules\Risk\Infrastructure\Models\UserLimit;

/**
 * Read model behind `GET /risk/limits` and `GET /risk/exposure` (§2.15).
 *
 * It exists because neither endpoint maps to a single existing service: the
 * limits screen is the risk profile, the Redis day counters and the caller's
 * own `user_limits` row read together, and the exposure screen is F18 from
 * Trading/Settlement joined to F20 from CollateralService. Composing that in a
 * controller would have put four collaborators and the utilisation arithmetic
 * into the HTTP layer, so it lives here and the controllers call one service
 * each, as every other endpoint does.
 *
 * Read-only by construction: nothing here opens a transaction or writes a row,
 * with the single exception inherited from `RiskProfileService::profile()`,
 * which materialises a default profile for a member that has never had one.
 * That is the same behaviour the pre-trade path relies on, so the API showing a
 * member their starting ceilings does not need a special case.
 *
 * No floats anywhere: utilisation is basis points computed with
 * `ExposureCalculator::utilisationBps()`, which is integer division.
 */
final class LimitUsageService
{
    public function __construct(
        private readonly RiskProfileService $profiles,
        private readonly DailyCounters $counters,
        private readonly ExposureCalculator $exposures,
        private readonly TradingExposureReaderInterface $exposureReader,
        private readonly CollateralService $collaterals,
    ) {}

    /**
     * Ceilings and today's consumption for one member, seen through the eyes of
     * one operator — the per-user row of check 10 is part of the answer, so the
     * same organisation gives two different results for two of its traders.
     */
    public function limits(int $organizationId, int $userId): LimitUsage
    {
        $profile = $this->profiles->profile($organizationId);
        $today = $this->counters->snapshot($organizationId);

        $userLimit = $this->userLimit($organizationId, $userId);
        $userUsedMg = $this->counters->userDailyVolumeMg($userId);
        $openOrders = $this->exposureReader->openOrderCount($organizationId);

        return new LimitUsage(
            organizationId: $organizationId,
            userId: $userId,
            riskLevel: $profile->risk_level->value,
            creditScore: $profile->credit_score,
            isTradingAllowed: $profile->is_trading_allowed,
            restrictionReason: $profile->restriction_reason,

            maxOrderMg: $profile->max_order_mg,
            maxDailyVolumeMg: $profile->max_daily_volume_mg,
            maxOpenOrders: $profile->max_open_orders,
            maxOpenExposureMg: $profile->max_open_exposure_mg,
            maxOpenExposureRial: $profile->max_open_exposure_rial,
            unsecuredCreditMg: $profile->unsecured_credit_mg,
            allowedSettlementTypes: array_values($profile->allowed_settlement_types),
            maxSettlementDays: $profile->max_settlement_days,

            dailyVolumeUsedMg: $today['volume_mg'],
            dailyValueUsedRial: $today['value_rial'],
            dailyTradeCount: $today['trade_count'],
            dailyVolumeRemainingMg: max(0, $profile->max_daily_volume_mg - $today['volume_mg']),
            dailyVolumeUtilisationBps: $this->exposures->utilisationBps(
                $today['volume_mg'],
                $profile->max_daily_volume_mg,
            ),
            openOrderCount: $openOrders,
            openOrderRemaining: max(0, $profile->max_open_orders - $openOrders),

            // An absent or deactivated row means the member-level ceiling is the
            // only one that applies — the same reading RiskGuard takes.
            userLimitSet: $userLimit !== null,
            userMaxOrderMg: $userLimit?->max_order_mg,
            userMaxDailyVolumeMg: $userLimit?->max_daily_volume_mg,
            userRequiresApprovalAboveMg: $userLimit?->requires_approval_above_mg,
            userDailyVolumeUsedMg: $userUsedMg,
            userDailyVolumeRemainingMg: $userLimit === null
                ? null
                : max(0, $userLimit->max_daily_volume_mg - $userUsedMg),
            userDailyVolumeUtilisationBps: $userLimit === null
                ? null
                : $this->exposures->utilisationBps($userUsedMg, $userLimit->max_daily_volume_mg),
        );
    }

    /** F18 and F20 for one member, as `GET /risk/exposure` renders them. */
    public function exposure(int $organizationId, int $instrumentId = 1): ExposureOverview
    {
        $profile = $this->profiles->profile($organizationId);

        $settlementGoldMg = $this->exposureReader->openGoldExposureMg($organizationId);
        $settlementRial = $this->exposureReader->openRialExposure($organizationId);

        // Open-order legs are folded into the reader's figures by the modules
        // that own orders; the calculator still takes them separately so the
        // split stays visible in the payload when a reader reports them apart.
        $exposure = $this->exposures->combine(
            settlementGoldMg: $settlementGoldMg,
            openOrderGoldMg: 0,
            settlementRial: $settlementRial,
            openOrderRial: 0,
        );

        $assessment = $this->collaterals->assess($organizationId, $instrumentId);

        return new ExposureOverview(
            organizationId: $organizationId,
            goldExposureMg: $exposure->goldMg,
            rialExposure: $exposure->rial,
            settlementGoldMg: $exposure->settlementGoldMg,
            openOrderGoldMg: $exposure->openOrderGoldMg,
            settlementRial: $exposure->settlementRial,
            openOrderRial: $exposure->openOrderRial,
            openOrderCount: $this->exposureReader->openOrderCount($organizationId),

            maxOpenExposureMg: $profile->max_open_exposure_mg,
            maxOpenExposureRial: $profile->max_open_exposure_rial,
            goldExposureUtilisationBps: $this->exposures->utilisationBps(
                $exposure->goldMg,
                $profile->max_open_exposure_mg,
            ),
            rialExposureUtilisationBps: $this->exposures->utilisationBps(
                $exposure->rial,
                $profile->max_open_exposure_rial,
            ),

            collateralValueRial: $assessment->collateralValueRial,
            exposureValueRial: $assessment->exposureValueRial,
            coverageRatioBps: $assessment->ratioBps,
            coverageStatus: $assessment->status->value,
            requiresMarginCall: $assessment->requiresMarginCall(),
            blocksNewTrades: $assessment->status->blocksNewTrades(),
            shortfallToHealthyRial: $assessment->shortfallToHealthyRial(),
            topUpDeadlineHours: $assessment->status->topUpDeadlineHours(),
        );
    }

    /**
     * The active per-user row, but only when it belongs to the caller's own
     * member. `user_limits.user_id` is globally unique, so a bare lookup by user
     * id would answer for a stranger's operator; the organisation predicate is
     * the tenancy leg, and it is asserted here rather than left to a scope.
     */
    private function userLimit(int $organizationId, int $userId): ?UserLimit
    {
        /** @var UserLimit|null $limit */
        $limit = UserLimit::query()
            ->active()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->first();

        return $limit;
    }
}
