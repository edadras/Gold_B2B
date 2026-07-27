<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

/**
 * One participant accepted a proposed netting batch (§5.6).
 *
 * allAccepted tells listeners whether this was the last outstanding answer, so
 * the operator UI can light up the execute button without re-counting.
 */
final readonly class NettingAccepted
{
    public function __construct(
        public int $batchId,
        public string $batchCode,
        public int $organizationId,
        public int $netPosition,
        public ?int $acceptedByUserId,
        public int $acceptedCount,
        public int $participantCount,
        public bool $allAccepted,
        public string $occurredAt,
    ) {}
}
