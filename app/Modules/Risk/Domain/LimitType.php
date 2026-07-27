<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain;

/**
 * Every ceiling RiskGuard can trip. docs/03-domain/11-risk-credit.md §11.4.
 */
enum LimitType: string
{
    case PER_ORDER = 'PER_ORDER';
    case DAILY_VOLUME = 'DAILY_VOLUME';
    case OPEN_ORDERS = 'OPEN_ORDERS';
    case OPEN_EXPOSURE = 'OPEN_EXPOSURE';
    case OPEN_EXPOSURE_RIAL = 'OPEN_EXPOSURE_RIAL';
    case COUNTERPARTY = 'COUNTERPARTY';
    case USER_ORDER = 'USER_ORDER';
    case USER_DAILY = 'USER_DAILY';

    public function label(): string
    {
        return match ($this) {
            self::PER_ORDER => 'سقف هر سفارش',
            self::DAILY_VOLUME => 'سقف حجم روزانه',
            self::OPEN_ORDERS => 'تعداد سفارش باز',
            self::OPEN_EXPOSURE => 'سقف تعهدات باز طلایی',
            self::OPEN_EXPOSURE_RIAL => 'سقف تعهدات باز ریالی',
            self::COUNTERPARTY => 'سقف طرف‌حساب',
            self::USER_ORDER => 'سقف سفارش کاربر',
            self::USER_DAILY => 'سقف روزانه کاربر',
        };
    }

    /** Gold limits are milligrams; the rest are rial or plain counts. */
    public function isWeightBased(): bool
    {
        return $this !== self::OPEN_EXPOSURE_RIAL && $this !== self::OPEN_ORDERS;
    }
}
