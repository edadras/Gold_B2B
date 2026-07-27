<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

final readonly class AuditRow
{
    public function __construct(
        public int $id,
        public string $occurredAt,
        public string $actorType,
        public ?int $actorId,
        public ?string $actorName,
        public ?int $organizationId,
        public string $action,
        public ?string $subjectType,
        public ?int $subjectId,
        public string $result,
        public ?string $failureReason,
    ) {}
}
