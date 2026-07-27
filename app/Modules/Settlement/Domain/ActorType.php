<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Domain;

/**
 * Who caused a transition — the actor_type column of settlement_events
 * (docs/04-data/02-schema-mysql.md §2.5).
 *
 * Appendix §2.14 rule 4: automatic transitions are recorded as SYSTEM.
 */
enum ActorType: string
{
    case USER = 'USER';
    case SYSTEM = 'SYSTEM';
    case PLATFORM_STAFF = 'PLATFORM_STAFF';

    /** A user id is mandatory when a human member acted. */
    public function requiresUserId(): bool
    {
        return $this === self::USER;
    }
}
