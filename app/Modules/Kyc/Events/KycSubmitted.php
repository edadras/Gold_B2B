<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Events;

/**
 * The member declared its dossier complete; it is now in the compliance queue.
 */
final readonly class KycSubmitted
{
    public function __construct(
        public int $organizationId,
        public int $kycProfileId,
        public string $organizationType,
        public int $submissionCount,
        public ?int $submittedByUserId,
        public string $occurredAt,
    ) {}
}
