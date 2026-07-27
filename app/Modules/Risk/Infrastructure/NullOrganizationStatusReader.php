<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure;

use App\Modules\Risk\Contracts\OrganizationStatusReaderInterface;
use Carbon\CarbonImmutable;

/**
 * Stand-in until Identity exposes its reader. It reports ACTIVE with no licence
 * expiry, which makes checks 2 and 3 pass. That is deliberate: the authority on
 * membership status is Identity, and inventing a restrictive answer here would
 * lock every member out of a system that has no Identity module yet.
 */
final class NullOrganizationStatusReader implements OrganizationStatusReaderInterface
{
    public const ACTIVE = 'ACTIVE';

    public function statusOf(int $organizationId): string
    {
        return self::ACTIVE;
    }

    public function licenseExpiresAt(int $organizationId): ?CarbonImmutable
    {
        return null;
    }
}
