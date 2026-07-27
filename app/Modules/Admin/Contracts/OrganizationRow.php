<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

final readonly class OrganizationRow
{
    public function __construct(
        public int $id,
        public string $displayName,
        public string $type,
        public string $status,
        public string $riskLevel,
        public string $complianceState,
        public ?string $city,
        public ?string $restrictionReason,
        public bool $isPlatform,
        public ?string $createdAt,
    ) {}
}
