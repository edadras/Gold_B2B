<?php

declare(strict_types=1);

namespace App\Modules\Custody\Events;

/** Same-purity lots were combined into one. No metal is created or lost. */
final readonly class LotsMerged
{
    /** @param list<int> $parentLotIds */
    public function __construct(
        public array $parentLotIds,
        public int $newLotId,
        public string $newLotCode,
        public int $ownerOrganizationId,
        public int $purityX10,
        public int $grossWeightMg,
        public int $fineWeightMg,
        public int $operationId,
        public ?int $requestedByUserId,
        public string $occurredAt,
    ) {}
}
