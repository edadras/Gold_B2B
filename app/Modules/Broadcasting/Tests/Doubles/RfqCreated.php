<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests\Doubles;

/**
 * Stand-in for App\Modules\Trading\Events\RfqCreated.
 *
 * WHY A DOUBLE AND NOT THE REAL CLASS. Broadcasting may depend only on Shared
 * and Identity, and tests/Architecture/ArchitectureTest.php enforces that over
 * every file in the module — test files included. Importing the real event here
 * would make the architecture suite fail, which is exactly right: this module
 * is built to know these events only by name and shape, so its tests are built
 * the same way. The class BASENAME matches the real one because the listeners
 * dispatch on it.
 */
final readonly class RfqCreated
{
    public function __construct(
        public int $rfqId,
        public string $rfqCode,
        public int $instrumentId,
        public int $organizationId,
        public string $side,
        public int $quantityMg,
        public string $visibility,
        public array $recipientOrgIds = [],
        public string $expiresAt = '',
        public string $occurredAt = '',
    ) {}
}
