<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

/**
 * A netting batch was computed and offered to its participants (§5.6,
 * "ایجاد NettingBatch با وضعیت PROPOSED").
 *
 * Nothing has been netted yet. ADR-009: every participant must accept before a
 * single ledger row is written.
 *
 * @property list<int> $participantOrganizationIds
 */
final readonly class NettingProposed
{
    /**
     * @param  list<int>  $participantOrganizationIds
     * @param  list<int>  $settlementIds
     */
    public function __construct(
        public int $batchId,
        public string $batchCode,
        public string $batchDate,
        public string $nettingType,
        public string $assetType,
        public array $participantOrganizationIds,
        public array $settlementIds,
        public int $grossTransferCount,
        public int $netTransferCount,
        public int $grossVolume,
        public int $netVolume,
        public ?string $acceptDeadlineAt,
        public string $occurredAt,
    ) {}
}
