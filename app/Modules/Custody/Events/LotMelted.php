<?php

declare(strict_types=1);

namespace App\Modules\Custody\Events;

/**
 * Inputs were physically melted. Outputs start DECLARED / UNDER_ASSAY and are
 * not tradable until a certificate arrives. lossFineMg feeds a
 * PROCESSING_LOSS ledger entry.
 */
final readonly class LotMelted
{
    /**
     * @param  list<int>  $inputLotIds
     * @param  list<int>  $outputLotIds
     */
    public function __construct(
        public array $inputLotIds,
        public array $outputLotIds,
        public int $ownerOrganizationId,
        public ?int $refinerId,
        public int $inputFineMg,
        public int $outputFineMg,
        public int $lossFineMg,
        public int $operationId,
        public ?int $requestedByUserId,
        public string $occurredAt,
    ) {}
}
