<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain;

/**
 * The thirteen dispute categories of docs/03-domain/13-dispute.md §13.2.
 *
 * The category is not decoration: it decides what evidence is required, whether
 * a quantity can be computed automatically, and whether a third-party re-assay
 * is an available remedy.
 */
enum DisputeType: string
{
    case WEIGHT_MISMATCH = 'WEIGHT_MISMATCH';
    case PURITY_MISMATCH = 'PURITY_MISMATCH';
    case AMOUNT_MISMATCH = 'AMOUNT_MISMATCH';
    case PAYMENT_NOT_RECEIVED = 'PAYMENT_NOT_RECEIVED';
    case PAYMENT_NOT_MADE = 'PAYMENT_NOT_MADE';
    case DELIVERY_NOT_MADE = 'DELIVERY_NOT_MADE';
    case DELIVERY_INCOMPLETE = 'DELIVERY_INCOMPLETE';
    case HALLMARK_MISMATCH = 'HALLMARK_MISMATCH';
    case OWNERSHIP_CLAIM = 'OWNERSHIP_CLAIM';
    case QUALITY_DEFECT = 'QUALITY_DEFECT';
    case SETTLEMENT_DELAY = 'SETTLEMENT_DELAY';
    case UNAUTHORIZED_TRADE = 'UNAUTHORIZED_TRADE';
    case SYSTEM_ERROR = 'SYSTEM_ERROR';

    public function label(): string
    {
        return match ($this) {
            self::WEIGHT_MISMATCH => 'اختلاف وزن',
            self::PURITY_MISMATCH => 'اختلاف عیار',
            self::AMOUNT_MISMATCH => 'اختلاف مبلغ',
            self::PAYMENT_NOT_RECEIVED => 'عدم دریافت وجه',
            self::PAYMENT_NOT_MADE => 'عدم پرداخت',
            self::DELIVERY_NOT_MADE => 'عدم تحویل',
            self::DELIVERY_INCOMPLETE => 'تحویل ناقص',
            self::HALLMARK_MISMATCH => 'مغایرت انگ',
            self::OWNERSHIP_CLAIM => 'ادعای مالکیت',
            self::QUALITY_DEFECT => 'عیب کیفی',
            self::SETTLEMENT_DELAY => 'تأخیر تسویه',
            self::UNAUTHORIZED_TRADE => 'معامله غیرمجاز',
            self::SYSTEM_ERROR => 'خطای سامانه',
        };
    }

    /** The evidence column of the §13.2 table; empty means none is mandated. */
    public function requiredEvidence(): string
    {
        return match ($this) {
            self::WEIGHT_MISMATCH => 'ویدئوی وزن‌کشی، رسید ترازو',
            self::PURITY_MISMATCH => 'گواهی ری‌گیری مستقل',
            self::AMOUNT_MISMATCH => 'محاسبه، رسید بانکی',
            self::PAYMENT_NOT_RECEIVED => 'صورت‌حساب بانکی',
            self::DELIVERY_INCOMPLETE => 'عکس، رسید',
            self::HALLMARK_MISMATCH => 'عکس قطعه و گواهی',
            self::OWNERSHIP_CLAIM => 'مستندات حقوقی',
            self::QUALITY_DEFECT => 'عکس، گزارش آزمایشگاه',
            default => '',
        };
    }

    public function requiresEvidence(): bool
    {
        return $this->requiredEvidence() !== '';
    }

    /**
     * Whether a third-party re-assay can settle this dispute (§13.6).
     *
     * Only claims about the metal itself; a payment dispute cannot be resolved
     * by weighing anything.
     */
    public function allowsThirdPartyReassay(): bool
    {
        return match ($this) {
            self::WEIGHT_MISMATCH,
            self::PURITY_MISMATCH,
            self::HALLMARK_MISMATCH,
            self::QUALITY_DEFECT,
            self::DELIVERY_INCOMPLETE => true,
            default => false,
        };
    }

    /**
     * Whether the disputed quantity can be derived from the claim itself.
     *
     * A purity claim states a number that produces an exact shortfall; a
     * "payment not received" claim does not, and the amount has to be given.
     */
    public function hasComputableShortfall(): bool
    {
        return $this === self::PURITY_MISMATCH || $this === self::WEIGHT_MISMATCH;
    }

    /** Default triage priority; an operator may raise it. */
    public function defaultPriority(): DisputePriority
    {
        return match ($this) {
            self::UNAUTHORIZED_TRADE, self::OWNERSHIP_CLAIM => DisputePriority::URGENT,
            self::PAYMENT_NOT_RECEIVED, self::PAYMENT_NOT_MADE,
            self::DELIVERY_NOT_MADE, self::SYSTEM_ERROR => DisputePriority::HIGH,
            self::SETTLEMENT_DELAY => DisputePriority::NORMAL,
            default => DisputePriority::NORMAL,
        };
    }
}
