<?php

declare(strict_types=1);

namespace App\Modules\Custody\Events;

/** The administrative hold was lifted; the lot is AVAILABLE again. */
final readonly class LotReleased
{
    public function __construct(
        public int $lotId,
        public string $lotCode,
        public int $ownerOrganizationId,
        public ?string $reason,
        public ?int $byUserId,
        public string $occurredAt,
    ) {}
}
