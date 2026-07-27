<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

/**
 * Partial restriction: sell-side / settlement only, reduced limits.
 *
 * Trading listens for this and cancels the member's open orders; settling
 * existing obligations remains permitted.
 */
final readonly class OrganizationRestricted
{
    public function __construct(
        public int $organizationId,
        public string $previousStatus,
        public string $reason,
        public ?int $actorUserId,
        public string $occurredAt,
    ) {}
}
