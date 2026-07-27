<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * The event → voucher mappings of docs/03-domain/09-accounting.md §9.3.
 *
 * One case per row of the mapping table, so a caller names the economic event
 * and PostingRules decides which accounts it touches — the accounts themselves
 * are never chosen at the call site.
 */
enum PostingRule: string
{
    case PURCHASE = 'PURCHASE';
    case SALE = 'SALE';
    case GOLD_DEPOSIT = 'GOLD_DEPOSIT';
    case GOLD_WITHDRAWAL = 'GOLD_WITHDRAWAL';
    case SEND_TO_REFINING = 'SEND_TO_REFINING';
    case MELT_LOSS = 'MELT_LOSS';
    case REFINING_COST = 'REFINING_COST';
    case ASSAY_ADJUSTMENT = 'ASSAY_ADJUSTMENT';
    case FEE = 'FEE';
    case PENALTY = 'PENALTY';
    case COUNTERPARTY_SETTLEMENT = 'COUNTERPARTY_SETTLEMENT';

    public function defaultSourceType(): SourceType
    {
        return match ($this) {
            self::PURCHASE, self::SALE => SourceType::TRADE,
            self::GOLD_DEPOSIT, self::GOLD_WITHDRAWAL, self::SEND_TO_REFINING => SourceType::CUSTODY,
            self::MELT_LOSS, self::REFINING_COST => SourceType::CUSTODY,
            self::ASSAY_ADJUSTMENT => SourceType::ASSAY,
            self::FEE => SourceType::FEE,
            self::PENALTY => SourceType::PENALTY,
            self::COUNTERPARTY_SETTLEMENT => SourceType::SETTLEMENT,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PURCHASE => 'خرید طلا',
            self::SALE => 'فروش طلا',
            self::GOLD_DEPOSIT => 'سپرده طلا در خزانه',
            self::GOLD_WITHDRAWAL => 'برداشت طلا',
            self::SEND_TO_REFINING => 'ارسال به ری‌گیری',
            self::MELT_LOSS => 'افت ذوب',
            self::REFINING_COST => 'هزینه ری‌گیری',
            self::ASSAY_ADJUSTMENT => 'تعدیل ری‌گیری مجدد',
            self::FEE => 'کارمزد سامانه',
            self::PENALTY => 'جریمه تأخیر',
            self::COUNTERPARTY_SETTLEMENT => 'تسویه بدهی طرف‌حساب',
        };
    }
}
