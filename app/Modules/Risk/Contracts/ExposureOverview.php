<?php

declare(strict_types=1);

namespace App\Modules\Risk\Contracts;

/**
 * "تعهدات باز" — open exposure (F18) valued against pledged collateral (F20),
 * for `GET /risk/exposure` of §2.15.
 *
 * The two halves are kept side by side on purpose: an exposure figure without
 * the coverage ratio tells a member nothing about whether a margin call is
 * coming, and §11.7 gives the member four hours to act once one is issued.
 *
 * `coverageRatioBps` is null when there is no exposure at all — a ratio with a
 * zero denominator is undefined, not infinite, and rendering it as "0%" would
 * read as the worst possible state instead of the best.
 */
final readonly class ExposureOverview
{
    public function __construct(
        public int $organizationId,
        // --- F18, split by where the obligation came from ---------------
        public int $goldExposureMg,
        public int $rialExposure,
        public int $settlementGoldMg,
        public int $openOrderGoldMg,
        public int $settlementRial,
        public int $openOrderRial,
        public int $openOrderCount,
        // --- the ceilings the above are measured against ----------------
        public int $maxOpenExposureMg,
        public int $maxOpenExposureRial,
        public ?int $goldExposureUtilisationBps,
        public ?int $rialExposureUtilisationBps,
        // --- F20 --------------------------------------------------------
        public int $collateralValueRial,
        public int $exposureValueRial,
        public ?int $coverageRatioBps,
        public string $coverageStatus,
        public bool $requiresMarginCall,
        public bool $blocksNewTrades,
        public int $shortfallToHealthyRial,
        public ?int $topUpDeadlineHours,
    ) {}
}
