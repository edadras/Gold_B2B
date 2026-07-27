<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

/**
 * Full suspension: no trading at all.
 *
 * Trading listens for this and cancels every open order of the member.
 * Identity does not — and must not — touch Trading's tables itself.
 */
final readonly class OrganizationSuspended
{
    public function __construct(
        public int $organizationId,
        public string $previousStatus,
        public string $reason,
        public ?int $actorUserId,
        public string $occurredAt,
    ) {}
}
