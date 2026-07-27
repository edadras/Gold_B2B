<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

/**
 * Role grants for a user changed. Carries both the before and after sets so an
 * audit listener can render the diff without re-reading Identity's tables.
 */
final readonly class UserRoleChanged
{
    /**
     * @param  list<string>  $previousRoles
     * @param  list<string>  $currentRoles
     */
    public function __construct(
        public int $userId,
        public int $organizationId,
        public array $previousRoles,
        public array $currentRoles,
        public ?int $actorUserId,
        public string $occurredAt,
    ) {}
}
