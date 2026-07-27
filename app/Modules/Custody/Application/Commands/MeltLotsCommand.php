<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Commands;

use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Exceptions\InvalidLotOperationException;

/** Melt N lots into M declared outputs. Mixed purities are allowed here. */
final readonly class MeltLotsCommand
{
    /**
     * @param  list<int>  $lotIds
     * @param  list<MeltOutputSpec>  $outputs
     */
    public function __construct(
        public array $lotIds,
        public array $outputs,
        public int $requestedByUserId,
        public ?int $refinerId = null,
        public ?CustodianType $outputCustodianType = null,
        public ?int $outputCustodianId = null,
        public ?string $reason = null,
        public ?string $referenceType = null,
        public ?int $referenceId = null,
    ) {
        if ($lotIds === []) {
            throw new InvalidLotOperationException('MELT', 'at least one input lot is required');
        }

        if (count(array_unique($lotIds)) !== count($lotIds)) {
            throw new InvalidLotOperationException('MELT', 'the same lot was listed twice');
        }

        if ($outputs === []) {
            throw new InvalidLotOperationException('MELT', 'at least one output bar is required');
        }

        if (($outputCustodianType === null) !== ($outputCustodianId === null)) {
            throw new InvalidLotOperationException('MELT', 'output custodian type and id must be given together');
        }
    }

    /** @return list<int> ascending — the lock order. */
    public function orderedLotIds(): array
    {
        $ids = $this->lotIds;
        sort($ids);

        return array_values($ids);
    }
}
