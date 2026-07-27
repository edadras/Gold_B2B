<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

/**
 * One account whose cached balance disagrees with the sum of its entries
 * (ledger invariant I2). The single most important number on the dashboard.
 */
final readonly class LedgerDiscrepancy
{
    public function __construct(
        public int $accountId,
        public int $organizationId,
        public string $assetType,
        public string $bucket,
        public int $storedBalance,
        public int $computedBalance,
        public int $difference,
    ) {}
}
