<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Events;

final readonly class DisputeStatusChanged
{
    public function __construct(
        public int $disputeId,
        public string $caseNumber,
        public string $fromStatus,
        public string $toStatus,
        public string $actorType,
        public ?int $actorUserId,
        public int $claimantOrgId,
        public int $respondentOrgId,
    ) {}
}
