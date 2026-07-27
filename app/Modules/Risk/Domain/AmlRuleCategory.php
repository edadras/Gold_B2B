<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain;

/** docs/03-domain/12-aml-compliance.md §12.3. */
enum AmlRuleCategory: string
{
    case VOLUME = 'VOLUME';
    case VELOCITY = 'VELOCITY';
    case PATTERN = 'PATTERN';
    case STRUCTURING = 'STRUCTURING';
    case COUNTERPARTY = 'COUNTERPARTY';
    case BEHAVIORAL = 'BEHAVIORAL';
    case SANCTIONS = 'SANCTIONS';

    public function label(): string
    {
        return match ($this) {
            self::VOLUME => 'حجم',
            self::VELOCITY => 'سرعت',
            self::PATTERN => 'الگو',
            self::STRUCTURING => 'تقسیم‌بندی',
            self::COUNTERPARTY => 'طرف‌حساب',
            self::BEHAVIORAL => 'رفتاری',
            self::SANCTIONS => 'تحریم و فهرست‌ها',
        };
    }
}
