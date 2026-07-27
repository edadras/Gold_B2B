<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

/**
 * The member is now ACTIVE. This is the trigger the Ledger module listens for
 * to open the organisation's gold and rial accounts, and the Risk module to
 * assign the initial limits.
 */
final readonly class OrganizationActivated
{
    public function __construct(
        public int $organizationId,
        public string $type,
        public string $riskLevel,
        public ?int $actorUserId,
        public string $occurredAt,
    ) {}
}
