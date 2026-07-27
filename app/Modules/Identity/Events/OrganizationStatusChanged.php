<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

/**
 * Emitted for *every* organisation transition, in addition to the specific
 * events above. Consumers that only care about a particular transition should
 * listen to the specific event; audit and notification listeners take this one.
 */
final readonly class OrganizationStatusChanged
{
    public function __construct(
        public int $organizationId,
        public string $fromStatus,
        public string $toStatus,
        public string $actorType,
        public ?int $actorUserId,
        public ?string $reason,
        public string $occurredAt,
    ) {}
}
