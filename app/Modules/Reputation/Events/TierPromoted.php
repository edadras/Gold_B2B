<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Events;

/**
 * A member reached a higher verification tier. Scalars only — an event that
 * carried a model would break the moment it crossed a queue boundary.
 *
 * Notification listens for this to send TIER_UPGRADED (§15.2).
 */
final readonly class TierPromoted
{
    public function __construct(
        public int $organizationId,
        public string $fromTier,
        public string $toTier,
        public string $occurredAt,
    ) {}
}
