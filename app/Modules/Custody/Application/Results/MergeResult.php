<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Results;

/** Outcome of a logical merge. A merge is lossless by definition. */
final readonly class MergeResult
{
    /** @param list<int> $inputLotIds */
    public function __construct(
        public array $inputLotIds,
        public int $newLotId,
        public string $newLotCode,
        public int $ownerOrganizationId,
        public int $purityX10,
        public int $grossWeightMg,
        public int $fineWeightMg,
        public int $inputFineMg,
        public int $operationId,
    ) {}

    public function conserves(): bool
    {
        return $this->fineWeightMg === $this->inputFineMg;
    }
}
