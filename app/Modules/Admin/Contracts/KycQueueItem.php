<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

final readonly class KycQueueItem
{
    public function __construct(
        public int $profileId,
        public int $organizationId,
        public string $organizationName,
        public string $status,
        public string $organizationStatus,
        public string $riskLevel,
        public ?string $submittedAt,
        public int $submissionCount,
        public int $documentCount,
    ) {}
}
