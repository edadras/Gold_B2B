<?php

declare(strict_types=1);

namespace App\Modules\Custody\Events;

/**
 * Administrative hold — dispute, AML flag or court order.
 * Trading must drop any resting orders backed by this lot.
 */
final readonly class LotHeld
{
    public function __construct(
        public int $lotId,
        public string $lotCode,
        public int $ownerOrganizationId,
        public string $reason,
        public string $previousStatus,
        public ?string $referenceType,
        public ?int $referenceId,
        public ?int $byUserId,
        public string $occurredAt,
    ) {}
}
