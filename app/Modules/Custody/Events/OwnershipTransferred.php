<?php

declare(strict_types=1);

namespace App\Modules\Custody\Events;

/**
 * The legal owner of a lot changed. Custody is told about this by Settlement;
 * the physical location does not move.
 */
final readonly class OwnershipTransferred
{
    public function __construct(
        public int $lotId,
        public string $lotCode,
        public int $fromOrganizationId,
        public int $toOrganizationId,
        public int $fineWeightMg,
        public ?string $referenceType,
        public ?int $referenceId,
        public ?int $actorUserId,
        public string $occurredAt,
    ) {}
}
