<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

/**
 * The member reports of docs/03-domain/15-notification-reporting.md §15.6.
 */
enum ReportType: string
{
    case GOLD_FLOW = 'GOLD_FLOW';
    case RIAL_FLOW = 'RIAL_FLOW';
    case TRADES = 'TRADES';
    case PNL = 'PNL';
    case INVENTORY = 'INVENTORY';
    case FEES = 'FEES';
    case DAILY_SUMMARY = 'DAILY_SUMMARY';

    public function label(): string
    {
        return match ($this) {
            self::GOLD_FLOW => 'گردش طلا',
            self::RIAL_FLOW => 'گردش ریال',
            self::TRADES => 'فهرست معاملات',
            self::PNL => 'سود و زیان',
            self::INVENTORY => 'موجودی lotها',
            self::FEES => 'کارمزدهای پرداختی',
            self::DAILY_SUMMARY => 'خلاصه روزانه',
        };
    }

    /**
     * Whether this report must end with a reconciliation check (§15.7).
     *
     * The flow reports state a closing balance, and a closing balance that
     * disagrees with the ledger is the one number a member must never be shown
     * without a warning attached.
     */
    public function requiresReconciliation(): bool
    {
        return $this === self::GOLD_FLOW || $this === self::RIAL_FLOW;
    }

    /**
     * Rows this report produces per day, roughly. Used only to guess whether it
     * can run inline — an estimate that is wrong in the safe direction (too
     * large) merely queues something that could have run inline.
     */
    public function estimatedRowsPerDay(): int
    {
        return match ($this) {
            self::GOLD_FLOW, self::RIAL_FLOW => 12,
            self::TRADES => 60,
            self::PNL => 20,
            self::INVENTORY => 200,
            self::FEES => 60,
            self::DAILY_SUMMARY => 1,
        };
    }
}
