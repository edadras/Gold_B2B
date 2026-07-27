<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Events;

/**
 * The licence lapsed. The member has already been moved to RESTRICTED by
 * LicenseExpiryService; this event is for notification and audit.
 */
final readonly class LicenseExpired
{
    public function __construct(
        public int $organizationId,
        public int $businessLicenseId,
        public string $licenseNo,
        public string $expiredAt,
        public bool $organizationRestricted,
        public string $occurredAt,
    ) {}
}
