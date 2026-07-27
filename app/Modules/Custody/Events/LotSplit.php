<?php

declare(strict_types=1);

namespace App\Modules\Custody\Events;

/**
 * A parent lot was cut into children.
 *
 * fineLossMg is the reason this event exists as far as accounting is
 * concerned: the Ledger posts PROCESSING_LOSS (real swarf) or ROUNDING (the
 * sub-milligram remainder of formula F1) from it. Custody never touches the
 * ledger itself.
 */
final readonly class LotSplit
{
    /**
     * @param  list<int>  $childLotIds
     * @param  list<int>  $childGrossWeightsMg
     * @param  list<int>  $childFineWeightsMg
     */
    public function __construct(
        public int $parentLotId,
        public array $childLotIds,
        public array $childGrossWeightsMg,
        public array $childFineWeightsMg,
        public int $ownerOrganizationId,
        public int $parentGrossMg,
        public int $parentFineMg,
        public int $grossLossMg,
        public int $fineLossMg,
        public int $operationId,
        public ?int $requestedByUserId,
        public string $occurredAt,
    ) {}

    /** True when the shortfall is pure F1 rounding rather than physical loss. */
    public function isRoundingOnly(): bool
    {
        return $this->grossLossMg === 0 && $this->fineLossMg > 0;
    }
}
