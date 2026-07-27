<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Events;

/**
 * Invariant I2 violated: the cached balance disagrees with Σ(entries).
 *
 * Emitted by ReconciliationService. Listeners are expected to freeze the
 * affected organisation and page operations — this is a critical alert, not a
 * warning (docs/03-domain/03-ledger.md §3.7).
 */
final readonly class BalanceDiscrepancyDetected
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
