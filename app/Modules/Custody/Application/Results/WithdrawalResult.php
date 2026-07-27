<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Results;

/** Outcome of handing the metal over at the counter. */
final readonly class WithdrawalResult
{
    /**
     * @param  list<int>  $lotIds
     * @param  list<string>  $lotCodes
     */
    public function __construct(
        public int $operationId,
        public string $waybillNo,
        public int $vaultId,
        public int $ownerOrganizationId,
        public array $lotIds,
        public array $lotCodes,
        public int $totalFineMg,
    ) {}
}
