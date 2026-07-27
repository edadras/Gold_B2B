<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Events;

/**
 * A compliance officer lowered a member's verification tier.
 *
 * The reason travels with the event because §14.4 requires the member to be
 * told why and how to recover; a notification that says only "your tier
 * changed" is the failure mode the rule exists to prevent.
 */
final readonly class TierDemoted
{
    public function __construct(
        public int $organizationId,
        public string $fromTier,
        public string $toTier,
        public int $reviewerUserId,
        public string $reason,
        public string $promotionLockedUntil,
        public string $occurredAt,
    ) {}
}
