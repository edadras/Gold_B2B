<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests\Doubles;

/**
 * Stand-in for App\Modules\Ledger\Events\TransferCompleted.
 *
 * WHY A DOUBLE AND NOT THE REAL CLASS. Broadcasting may depend only on Shared
 * and Identity, and tests/Architecture/ArchitectureTest.php enforces that over
 * every file in the module — test files included. Importing the real event here
 * would make the architecture suite fail, which is exactly right: this module
 * is built to know these events only by name and shape, so its tests are built
 * the same way. The class BASENAME matches the real one because the listeners
 * dispatch on it.
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
