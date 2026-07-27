<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Results;

use App\Modules\Shared\ValueObjects\FineWeight;

/**
 * Outcome of a split.
 *
 * $fineLossMg is the number the Ledger needs: it is the fine weight that
 * existed on the parent and no longer exists on any child. It is the sum of
 * the F1 rounding remainder and any physical loss, and the caller is expected
 * to post it as PROCESSING_LOSS / ROUNDING. Custody deliberately does not
 * write ledger rows (AGENT_BRIEF rule 7).
 *
 * Worked example 2 (docs/11-appendix/03-worked-examples.md): a 502,513 mg
 * lot at purity 9950 split for 250,000 mg fine yields children of 251,257 and
 * 251,256 mg gross, 250,000 and 249,999 mg fine, and $fineLossMg === 1.
 */
final readonly class SplitResult
{
    /**
     * @param  list<int>  $childLotIds
     * @param  list<string>  $childLotCodes
     * @param  list<int>  $childGrossMg
     * @param  list<int>  $childFineMg
     */
    public function __construct(
        public int $parentLotId,
        public string $parentLotCode,
        public int $ownerOrganizationId,
        public array $childLotIds,
        public array $childLotCodes,
        public array $childGrossMg,
        public array $childFineMg,
        public int $parentGrossMg,
        public int $parentFineMg,
        public int $grossLossMg,
        public int $fineLossMg,
        public int $operationId,
    ) {}

    public function childCount(): int
    {
        return count($this->childLotIds);
    }

    public function totalChildGrossMg(): int
    {
        return array_sum($this->childGrossMg);
    }

    public function totalChildFineMg(): int
    {
        return array_sum($this->childFineMg);
    }

    public function fineLoss(): FineWeight
    {
        return FineWeight::fromMilligrams($this->fineLossMg);
    }

    /** Σ children + loss == parent, on both dimensions. */
    public function conserves(): bool
    {
        return $this->totalChildGrossMg() + $this->grossLossMg === $this->parentGrossMg
            && $this->totalChildFineMg() + $this->fineLossMg === $this->parentFineMg;
    }

    public function hasLoss(): bool
    {
        return $this->fineLossMg > 0;
    }
}
