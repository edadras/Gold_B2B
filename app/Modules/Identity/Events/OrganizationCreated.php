<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

/**
 * A member organisation has been registered and is awaiting document
 * submission. Scalars only — never an Eloquent model, so a listener in another
 * module cannot reach back into Identity's persistence.
 */
final readonly class OrganizationCreated
{
    public function __construct(
        public int $organizationId,
        public string $type,
        public string $displayName,
        public string $city,
        public string $occurredAt,
    ) {}
}
