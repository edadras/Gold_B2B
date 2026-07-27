<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * AML posture applied to a member, independent of its membership status.
 */
enum ComplianceState: string
{
    case NORMAL = 'NORMAL';
    case MONITORED = 'MONITORED';
    case ENHANCED_DUE_DILIGENCE = 'ENHANCED_DUE_DILIGENCE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
