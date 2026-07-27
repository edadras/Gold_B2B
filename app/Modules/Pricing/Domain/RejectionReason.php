<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

/**
 * Why an incoming tick was not accepted. docs/03-domain/07-pricing.md §7.4.
 *
 * Filter 3 (cross-source deviation) is deliberately absent: it flags and
 * substitutes the median, it never rejects.
 */
enum RejectionReason: string
{
    case STALE = 'STALE';
    case OUTLIER = 'OUTLIER';
    case OUT_OF_RANGE = 'OUT_OF_RANGE';
    case OUT_OF_ORDER = 'OUT_OF_ORDER';

    public function label(): string
    {
        return match ($this) {
            self::STALE => 'قیمت کهنه است',
            self::OUTLIER => 'انحراف غیرعادی از آخرین قیمت پذیرفته‌شده',
            self::OUT_OF_RANGE => 'مقدار خارج از بازه منطقی',
            self::OUT_OF_ORDER => 'زمان اعلام قدیمی‌تر از tick قبلی همان منبع',
        };
    }

    /** Filter 2 outliers are held for a human to confirm, the rest are dropped. */
    public function needsManualConfirmation(): bool
    {
        return $this === self::OUTLIER;
    }
}
