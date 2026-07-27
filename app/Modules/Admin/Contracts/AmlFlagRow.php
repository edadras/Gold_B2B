<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

final readonly class AmlFlagRow
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public int $id,
        public string $ruleCode,
        public ?string $ruleName,
        public int $organizationId,
        public ?string $organizationName,
        public ?int $userId,
        public string $severity,
        public string $status,
        public string $summary,
        public ?string $subjectType,
        public ?int $subjectId,
        public string $raisedAt,
        public ?int $assignedToUserId,
        public ?string $resolutionNotes,
        public array $context = [],
    ) {}
}
