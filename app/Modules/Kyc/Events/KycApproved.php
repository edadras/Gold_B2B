<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Events;

/**
 * Compliance approved the dossier. The organisation has been moved
 * VERIFIED → ACTIVE, which is what makes Ledger open its accounts.
 */
final readonly class KycApproved
{
    public function __construct(
        public int $organizationId,
        public int $kycProfileId,
        public int $reviewerUserId,
        public string $notes,
        public ?string $nextReviewDueAt,
        public string $occurredAt,
    ) {}
}
