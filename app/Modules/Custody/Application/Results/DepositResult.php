<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Results;

/** Receipt data for a completed deposit — docs §6.3 step 9. */
final readonly class DepositResult
{
    /**
     * @param  list<int>  $lotIds
     * @param  list<string>  $lotCodes
     */
    public function __construct(
        public int $operationId,
        public int $vaultId,
        public int $ownerOrganizationId,
        public array $lotIds,
        public array $lotCodes,
        public int $totalGrossMg,
        public int $totalFineMg,
        /** Lots that arrived without a valid certificate and went UNDER_ASSAY. */
        public array $pendingAssayLotIds,
    ) {}

    public function pieceCount(): int
    {
        return count($this->lotIds);
    }
}
