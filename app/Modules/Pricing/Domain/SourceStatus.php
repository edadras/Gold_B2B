<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

/**
 * Health of an external feed. docs/03-domain/07-pricing.md §7.4.
 */
enum SourceStatus: string
{
    case ACTIVE = 'ACTIVE';
    case DEGRADED = 'DEGRADED';
    case DOWN = 'DOWN';

    public function isUsable(): bool
    {
        return $this !== self::DOWN;
    }

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'سالم',
            self::DEGRADED => 'کاهش‌یافته',
            self::DOWN => 'قطع',
        };
    }
}
