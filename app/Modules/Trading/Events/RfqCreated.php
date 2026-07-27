<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/**
 * A request for quote went out.
 *
 * $organizationId is the requester; Notification must respect $visibility and
 * withhold the identity when it is ANONYMOUS (§4.7 rule 5).
 *
 * @property list<int> $recipientOrgIds
 */
final readonly class RfqCreated
{
    /** @param list<int> $recipientOrgIds */
    public function __construct(
        public int $rfqId,
        public string $rfqCode,
        public int $instrumentId,
        public int $organizationId,
        public string $side,
        public int $quantityMg,
        public string $visibility,
        public array $recipientOrgIds,
        public string $expiresAt,
        public string $occurredAt,
    ) {}
}
