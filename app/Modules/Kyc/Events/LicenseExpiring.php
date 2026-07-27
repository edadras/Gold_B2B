<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Events;

/**
 * One rung of the 30/10/1-day reminder ladder (docs §1.6). The channel escalates
 * with urgency: 30d in-app + email, 10d adds SMS, 1d adds a dashboard banner.
 * Choosing channels is the notification module's job, not ours.
 */
final readonly class LicenseExpiring
{
    public function __construct(
        public int $organizationId,
        public int $businessLicenseId,
        public string $licenseNo,
        public int $daysRemaining,
        public string $expiresAt,
        public string $occurredAt,
    ) {}
}
