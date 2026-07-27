<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Results;

use App\Modules\Custody\Domain\Enums\VarianceClassification;

/** Outcome of a physical stock count — docs §6.6 step 4-6. */
final readonly class ReconciliationReport
{
    /** @param list<VarianceLine> $lines */
    public function __construct(
        public int $auditId,
        public string $auditCode,
        public int $vaultId,
        public array $lines,
        public int $expectedLotCount,
        public int $expectedGrossMg,
        public int $expectedFineMg,
        public int $countedLotCount,
        public int $countedGrossMg,
        public bool $requiresInvestigation,
        public bool $vaultFrozen,
    ) {}

    /** @return list<VarianceLine> */
    public function linesOf(VarianceClassification $classification): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (VarianceLine $l): bool => $l->classification === $classification,
        ));
    }

    public function countOf(VarianceClassification $classification): int
    {
        return count($this->linesOf($classification));
    }

    /** @return list<VarianceLine> everything that is not a clean match */
    public function variances(): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (VarianceLine $l): bool => $l->classification->isVariance(),
        ));
    }

    public function isClean(): bool
    {
        return $this->variances() === [];
    }
}
