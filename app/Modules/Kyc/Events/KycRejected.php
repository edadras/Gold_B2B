<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Events;

final readonly class KycRejected
{
    public function __construct(
        public int $organizationId,
        public int $kycProfileId,
        public int $reviewerUserId,
        public string $notes,
        public string $occurredAt,
    ) {}
}
