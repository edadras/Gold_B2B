<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Events;

/**
 * Value moved between two organisations, both legs written and the group
 * verified to sum to zero.
 */
final readonly class TransferCompleted
{
    public function __construct(
        public int $fromOrganizationId,
        public int $toOrganizationId,
        public string $assetType,
        public int $amount,
        public string $fromBucket,
        public string $toBucket,
        public string $transactionGroup,
        public int $debitEntryId,
        public int $creditEntryId,
        public string $referenceType,
        public int $referenceId,
    ) {}
}
