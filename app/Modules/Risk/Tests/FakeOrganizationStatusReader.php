<?php

declare(strict_types=1);

namespace App\Modules\Risk\Tests;

use App\Modules\Risk\Contracts\OrganizationStatusReaderInterface;
use Carbon\CarbonImmutable;

/** Controllable stand-in for Identity. */
final class FakeOrganizationStatusReader implements OrganizationStatusReaderInterface
{
    public function __construct(
        public string $status = 'ACTIVE',
        public ?CarbonImmutable $licenseExpiresAt = null,
    ) {}

    public function statusOf(int $organizationId): string
    {
        return $this->status;
    }

    public function licenseExpiresAt(int $organizationId): ?CarbonImmutable
    {
        return $this->licenseExpiresAt;
    }
}
