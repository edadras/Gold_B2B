<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain;

/** F20 result. */
final readonly class CoverageAssessment
{
    public function __construct(
        public int $collateralValueRial,
        public int $exposureValueRial,
        public ?int $ratioBps,
        public CoverageStatus $status,
    ) {}

    public function requiresMarginCall(): bool
    {
        return $this->status->requiresMarginCall();
    }

    /** Extra collateral needed to climb back to the healthy band. */
    public function shortfallToHealthyRial(): int
    {
        if ($this->exposureValueRial <= 0) {
            return 0;
        }

        $needed = intdiv($this->exposureValueRial * 15_000, 10_000);

        return max($needed - $this->collateralValueRial, 0);
    }
}
