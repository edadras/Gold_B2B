<?php

declare(strict_types=1);

namespace App\Modules\Risk\Contracts;

/**
 * Everything the credit score (F19) and the limit-increase prerequisites
 * (§11.8) need about a member, gathered once.
 */
final readonly class MemberActivityStats
{
    public function __construct(
        public int $settlementsOnTime = 0,
        public int $settlementsTotal = 0,
        public int $monthsActive = 0,
        public int $totalVolumeMg = 0,
        public int $disputesLost = 0,
        public int $totalTrades = 0,
        public int $kycVerifiedItems = 0,
        public int $kycTotalItems = 0,
        public int $distinctCounterparties = 0,
        public int $activeAmlFlags = 0,
        public int $defaultsLast180Days = 0,
        public int $settledTradeCount = 0,
        public int $daysActive = 0,
        public bool $kycCurrent = false,
    ) {}

    /**
     * On-time settlement rate in basis points, or null when there is no history
     * — docs/11-appendix/01-formulas.md §1.11 case 6 says null, not 100%.
     */
    public function onTimeRateBps(): ?int
    {
        if ($this->settlementsTotal === 0) {
            return null;
        }

        return intdiv($this->settlementsOnTime * 10_000, $this->settlementsTotal);
    }

    public function kycComplete(): bool
    {
        return $this->kycTotalItems > 0
            && $this->kycVerifiedItems === $this->kycTotalItems
            && $this->kycCurrent;
    }
}
