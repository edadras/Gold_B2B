<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * What produced a voucher — the left half of the idempotency key
 * `UNIQUE (organization_id, source_type, source_id)` from §9.2.
 *
 * A redelivered domain event carries the same (type, id) pair, so the unique
 * index turns "post the voucher" into an operation that is safe to repeat.
 */
enum SourceType: string
{
    case TRADE = 'trade';
    case SETTLEMENT = 'settlement';
    case CUSTODY = 'custody';
    case FEE = 'fee';
    case PENALTY = 'penalty';
    case ASSAY = 'assay';
    case ADJUSTMENT = 'adjustment';
    case PERIOD_CLOSE = 'period_close';
    case MANUAL = 'manual';

    /**
     * The mirror voucher that cancels another one. Its source_id is the id of
     * the voucher being reversed, which keeps reversal idempotent — reversing
     * the same entry twice collides on uq_source and returns the first mirror.
     */
    case REVERSAL = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::TRADE => 'معامله',
            self::SETTLEMENT => 'تسویه',
            self::CUSTODY => 'عملیات خزانه',
            self::FEE => 'کارمزد',
            self::PENALTY => 'جریمه',
            self::ASSAY => 'ری‌گیری',
            self::ADJUSTMENT => 'تعدیل',
            self::PERIOD_CLOSE => 'بستن دوره',
            self::MANUAL => 'سند دستی',
            self::REVERSAL => 'سند ابطال',
        };
    }
}
