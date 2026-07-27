<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

/**
 * The batch was posted as one balanced transaction_group and every settlement
 * in it moved to SETTLED (§5.6, last two steps of the process diagram).
 *
 * clearingResidual is asserted to be zero before this fires: a multilateral
 * batch routes through SYSTEM/CLEARING and must leave it exactly as it found
 * it (worked example 3, "حساب CLEARING در پایان صفر است").
 *
 * @property list<int> $settlementIds
 */
final readonly class NettingExecuted
{
    /**
     * @param  list<int>  $settlementIds
     * @param  list<int>  $participantOrganizationIds
     */
    public function __construct(
        public int $batchId,
        public string $batchCode,
        public string $nettingType,
        public string $assetType,
        public array $settlementIds,
        public array $participantOrganizationIds,
        public int $grossTransferCount,
        public int $netTransferCount,
        public int $grossVolume,
        public int $netVolume,
        public int $clearingResidual,
        public string $transactionGroup,
        public string $occurredAt,
    ) {}
}
