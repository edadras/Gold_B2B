<?php

declare(strict_types=1);

namespace App\Modules\Risk\Contracts;

use Carbon\CarbonImmutable;

/**
 * Checks 2 and 3 of §11.4 need the member's status and licence expiry. Identity
 * owns both; Risk reads them through this interface.
 */
interface OrganizationStatusReaderInterface
{
    /** Organization status code, e.g. ACTIVE, RESTRICTED, SUSPENDED. */
    public function statusOf(int $organizationId): string;

    /** Null means "no licence expiry recorded", which is never treated as expired. */
    public function licenseExpiresAt(int $organizationId): ?CarbonImmutable;
}
