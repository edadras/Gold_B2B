<?php

declare(strict_types=1);

namespace App\Modules\Risk\Contracts;

/**
 * "سقف‌ها و مصرف" — every ceiling that can stop a trade, next to how much of it
 * today has already spent (docs/05-api/02-endpoints.md §2.15, `GET /risk/limits`).
 *
 * Everything is an integer: milligrams for weight, rial for money, basis points
 * for utilisation, plain counts for orders. A percentage would have to be a
 * float, so utilisation is bps throughout (4_800 = 48.00%).
 *
 * `null` utilisation means "no ceiling is set", which is different from 0%.
 */
final readonly class LimitUsage
{
    /** @param list<string> $allowedSettlementTypes */
    public function __construct(
        public int $organizationId,
        public int $userId,
        public string $riskLevel,
        public int $creditScore,
        public bool $isTradingAllowed,
        public ?string $restrictionReason,
        // --- member-level ceilings -------------------------------------
        public int $maxOrderMg,
        public int $maxDailyVolumeMg,
        public int $maxOpenOrders,
        public int $maxOpenExposureMg,
        public int $maxOpenExposureRial,
        public int $unsecuredCreditMg,
        public array $allowedSettlementTypes,
        public int $maxSettlementDays,
        // --- today's consumption ---------------------------------------
        public int $dailyVolumeUsedMg,
        public int $dailyValueUsedRial,
        public int $dailyTradeCount,
        public int $dailyVolumeRemainingMg,
        public ?int $dailyVolumeUtilisationBps,
        public int $openOrderCount,
        public int $openOrderRemaining,
        // --- this operator's own ceiling (check 10 of §11.4) -----------
        public bool $userLimitSet,
        public ?int $userMaxOrderMg,
        public ?int $userMaxDailyVolumeMg,
        public ?int $userRequiresApprovalAboveMg,
        public int $userDailyVolumeUsedMg,
        public ?int $userDailyVolumeRemainingMg,
        public ?int $userDailyVolumeUtilisationBps,
    ) {}
}
