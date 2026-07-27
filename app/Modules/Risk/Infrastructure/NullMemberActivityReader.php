<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure;

use App\Modules\Risk\Contracts\MemberActivityReaderInterface;
use App\Modules\Risk\Contracts\MemberActivityStats;

/**
 * Empty history. A member scored from this lands at CRITICAL, which is why
 * RiskProfileService seeds new members from RiskLevel::forNewMember() instead
 * of from a computed score.
 */
final class NullMemberActivityReader implements MemberActivityReaderInterface
{
    public function statsFor(int $organizationId): MemberActivityStats
    {
        return new MemberActivityStats;
    }
}
