<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Events;

/**
 * AVAILABLE → RESERVED completed. Dispatched after the transaction commits.
 */
final readonly class BalanceReserved
{
    public function __construct(
        public int $organizationId,
        public string $assetType,
        public int $amount,
        public int $reservationEntryId,
        public string $transactionGroup,
        public string $referenceType,
        public int $referenceId,
        public int $availableAfter,
    ) {}
}
