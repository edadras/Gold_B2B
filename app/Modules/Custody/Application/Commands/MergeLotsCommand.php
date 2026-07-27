<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Commands;

use App\Modules\Custody\Domain\Enums\LotShape;
use App\Modules\Custody\Domain\Exceptions\InvalidLotOperationException;

/** Logical merge — no melting, so every input must already share a purity. */
final readonly class MergeLotsCommand
{
    /** @param list<int> $lotIds */
    public function __construct(
        public array $lotIds,
        public int $requestedByUserId,
        public ?LotShape $outputShape = null,
        public ?string $serialNumber = null,
        public ?string $reason = null,
        public ?string $referenceType = null,
        public ?int $referenceId = null,
    ) {
        if (count($lotIds) < 2) {
            throw new InvalidLotOperationException('MERGE', 'a merge needs at least two input lots');
        }

        if (count(array_unique($lotIds)) !== count($lotIds)) {
            throw new InvalidLotOperationException('MERGE', 'the same lot was listed twice');
        }
    }

    /** @return list<int> ascending, which is also the lock order (AGENT_BRIEF rule 4). */
    public function orderedLotIds(): array
    {
        $ids = $this->lotIds;
        sort($ids);

        return array_values($ids);
    }
}
