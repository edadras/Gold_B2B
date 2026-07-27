<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Events;

/**
 * Documents are incomplete. `missingItems` names exactly what is wrong so the
 * notification can be specific — "اعلان با ذکر دقیق نقص" (docs §1.2).
 */
final readonly class KycInfoRequired
{
    /** @param  list<string>  $missingItems */
    public function __construct(
        public int $organizationId,
        public int $kycProfileId,
        public int $reviewerUserId,
        public string $notes,
        public array $missingItems,
        public string $occurredAt,
    ) {}
}
