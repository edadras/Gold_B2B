<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

/**
 * The notification catalogue of docs/03-domain/15-notification-reporting.md §15.2.
 *
 * Every code carries its own category, priority, default channels and Persian
 * templates, so a caller only ever names the event and supplies parameters —
 * there is no way for two call sites to disagree about how a settlement
 * deadline is worded, or for one of them to quietly send an SMS the catalogue
 * did not authorise.
 *
 * Templates use `:name` placeholders, rendered by the dispatcher.
 *
 * `defaultChannels()` reproduces the doc's table verbatim. IN_APP is added by
 * the dispatcher regardless, because §15.1 gives In-App every notification —
 * so a code whose table entry is "Push + SMS" still leaves a row in the feed.
 */
enum NotificationCode: string
{
    // --- معاملات / trading ---------------------------------------------------
    case ORDER_PLACED = 'ORDER_PLACED';
    case ORDER_FILLED = 'ORDER_FILLED';
    case ORDER_PARTIAL = 'ORDER_PARTIAL';
    case ORDER_CANCELLED = 'ORDER_CANCELLED';
    case ORDER_EXPIRED = 'ORDER_EXPIRED';
    case ORDER_REJECTED = 'ORDER_REJECTED';
    case OTC_OFFER_RECEIVED = 'OTC_OFFER_RECEIVED';
    case OTC_OFFER_ACCEPTED = 'OTC_OFFER_ACCEPTED';
    case RFQ_RECEIVED = 'RFQ_RECEIVED';
    case RFQ_QUOTED = 'RFQ_QUOTED';
    case RFQ_ACCEPTED = 'RFQ_ACCEPTED';
    case RFQ_EXPIRING = 'RFQ_EXPIRING';

    // --- تسویه / settlement --------------------------------------------------
    case SETTLEMENT_OPENED = 'SETTLEMENT_OPENED';
    case PAYMENT_REQUIRED = 'PAYMENT_REQUIRED';
    case PAYMENT_DECLARED = 'PAYMENT_DECLARED';
    case PAYMENT_CONFIRMED = 'PAYMENT_CONFIRMED';
    case GOLD_TRANSFERRED = 'GOLD_TRANSFERRED';
    case SETTLEMENT_COMPLETED = 'SETTLEMENT_COMPLETED';
    case SETTLEMENT_DUE_SOON = 'SETTLEMENT_DUE_SOON';
    case SETTLEMENT_OVERDUE = 'SETTLEMENT_OVERDUE';
    case SETTLEMENT_DEFAULTED = 'SETTLEMENT_DEFAULTED';
    case NETTING_PROPOSED = 'NETTING_PROPOSED';
    case NETTING_EXECUTED = 'NETTING_EXECUTED';

    // --- دارایی / asset ------------------------------------------------------
    case GOLD_RESERVED = 'GOLD_RESERVED';
    case GOLD_RELEASED = 'GOLD_RELEASED';
    case LOW_GOLD_BALANCE = 'LOW_GOLD_BALANCE';
    case LOW_RIAL_BALANCE = 'LOW_RIAL_BALANCE';
    case VAULT_DEPOSIT_DONE = 'VAULT_DEPOSIT_DONE';
    case VAULT_WITHDRAWAL_APPROVED = 'VAULT_WITHDRAWAL_APPROVED';
    case ASSAY_COMPLETED = 'ASSAY_COMPLETED';
    case ASSAY_VARIANCE = 'ASSAY_VARIANCE';

    // --- حساب و انطباق / account ---------------------------------------------
    case KYC_APPROVED = 'KYC_APPROVED';
    case KYC_INFO_REQUIRED = 'KYC_INFO_REQUIRED';
    case KYC_REJECTED = 'KYC_REJECTED';
    case LICENSE_EXPIRING_30 = 'LICENSE_EXPIRING_30';
    case LICENSE_EXPIRING_10 = 'LICENSE_EXPIRING_10';
    case LICENSE_EXPIRED = 'LICENSE_EXPIRED';
    case LIMIT_INCREASED = 'LIMIT_INCREASED';
    case ACCOUNT_RESTRICTED = 'ACCOUNT_RESTRICTED';
    case NEW_LOGIN = 'NEW_LOGIN';
    case TIER_UPGRADED = 'TIER_UPGRADED';

    // --- اختلاف / dispute ----------------------------------------------------
    case DISPUTE_OPENED_AGAINST = 'DISPUTE_OPENED_AGAINST';
    case DISPUTE_REPLY_DUE = 'DISPUTE_REPLY_DUE';
    case DISPUTE_MESSAGE = 'DISPUTE_MESSAGE';
    case DISPUTE_RESOLVED = 'DISPUTE_RESOLVED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function category(): Category
    {
        return match ($this) {
            self::ORDER_PLACED, self::ORDER_FILLED, self::ORDER_PARTIAL,
            self::ORDER_CANCELLED, self::ORDER_EXPIRED, self::ORDER_REJECTED,
            self::OTC_OFFER_RECEIVED, self::OTC_OFFER_ACCEPTED,
            self::RFQ_RECEIVED, self::RFQ_QUOTED, self::RFQ_ACCEPTED,
            self::RFQ_EXPIRING => Category::TRADING,

            self::SETTLEMENT_OPENED, self::PAYMENT_REQUIRED, self::PAYMENT_DECLARED,
            self::PAYMENT_CONFIRMED, self::GOLD_TRANSFERRED, self::SETTLEMENT_COMPLETED,
            self::SETTLEMENT_DUE_SOON, self::SETTLEMENT_OVERDUE, self::SETTLEMENT_DEFAULTED,
            self::NETTING_PROPOSED, self::NETTING_EXECUTED => Category::SETTLEMENT,

            self::GOLD_RESERVED, self::GOLD_RELEASED, self::LOW_GOLD_BALANCE,
            self::LOW_RIAL_BALANCE, self::VAULT_DEPOSIT_DONE,
            self::VAULT_WITHDRAWAL_APPROVED, self::ASSAY_COMPLETED,
            self::ASSAY_VARIANCE => Category::ASSET,

            self::KYC_APPROVED, self::KYC_INFO_REQUIRED, self::KYC_REJECTED,
            self::LICENSE_EXPIRING_30, self::LICENSE_EXPIRING_10, self::LICENSE_EXPIRED,
            self::LIMIT_INCREASED, self::ACCOUNT_RESTRICTED, self::NEW_LOGIN,
            self::TIER_UPGRADED => Category::ACCOUNT,

            self::DISPUTE_OPENED_AGAINST, self::DISPUTE_REPLY_DUE,
            self::DISPUTE_MESSAGE, self::DISPUTE_RESOLVED => Category::DISPUTE,
        };
    }

    public function priority(): Priority
    {
        return match ($this) {
            self::RFQ_ACCEPTED, self::PAYMENT_DECLARED, self::SETTLEMENT_OVERDUE,
            self::SETTLEMENT_DEFAULTED, self::ASSAY_VARIANCE, self::LICENSE_EXPIRED,
            self::ACCOUNT_RESTRICTED, self::DISPUTE_OPENED_AGAINST,
            self::DISPUTE_REPLY_DUE, self::DISPUTE_RESOLVED => Priority::CRITICAL,

            self::ORDER_FILLED, self::ORDER_PARTIAL, self::ORDER_REJECTED,
            self::OTC_OFFER_RECEIVED, self::OTC_OFFER_ACCEPTED, self::RFQ_RECEIVED,
            self::RFQ_QUOTED, self::RFQ_EXPIRING, self::PAYMENT_REQUIRED,
            self::PAYMENT_CONFIRMED, self::GOLD_TRANSFERRED, self::SETTLEMENT_DUE_SOON,
            self::NETTING_PROPOSED, self::LOW_GOLD_BALANCE, self::LOW_RIAL_BALANCE,
            self::VAULT_DEPOSIT_DONE, self::VAULT_WITHDRAWAL_APPROVED,
            self::ASSAY_COMPLETED, self::KYC_APPROVED, self::KYC_INFO_REQUIRED,
            self::KYC_REJECTED, self::LICENSE_EXPIRING_10, self::NEW_LOGIN,
            self::DISPUTE_MESSAGE => Priority::HIGH,

            default => Priority::NORMAL,
        };
    }

    /**
     * The doc's "channel" column, verbatim.
     *
     * @return list<Channel>
     */
    public function defaultChannels(): array
    {
        return match ($this) {
            self::ORDER_PLACED, self::ORDER_CANCELLED, self::ORDER_EXPIRED,
            self::SETTLEMENT_OPENED, self::NETTING_EXECUTED,
            self::GOLD_RESERVED, self::GOLD_RELEASED => [Channel::IN_APP],

            self::RFQ_EXPIRING, self::LIMIT_INCREASED,
            self::DISPUTE_MESSAGE => [Channel::PUSH],

            self::LICENSE_EXPIRING_30 => [Channel::IN_APP, Channel::EMAIL],

            self::RFQ_ACCEPTED, self::PAYMENT_DECLARED, self::SETTLEMENT_DUE_SOON,
            self::SETTLEMENT_OVERDUE, self::SETTLEMENT_DEFAULTED,
            self::VAULT_DEPOSIT_DONE, self::VAULT_WITHDRAWAL_APPROVED,
            self::ASSAY_VARIANCE, self::KYC_APPROVED, self::KYC_INFO_REQUIRED,
            self::KYC_REJECTED, self::LICENSE_EXPIRING_10, self::LICENSE_EXPIRED,
            self::ACCOUNT_RESTRICTED, self::NEW_LOGIN,
            self::DISPUTE_OPENED_AGAINST, self::DISPUTE_REPLY_DUE,
            self::DISPUTE_RESOLVED => [Channel::PUSH, Channel::SMS],

            default => [Channel::PUSH, Channel::IN_APP],
        };
    }

    /**
     * §15.4 rule 6: SMS is reserved for critical, financial-with-immediate-effect
     * and security codes. An SMS costs money on every send, and a channel that
     * cries wolf stops being read exactly when it matters.
     */
    public function allowsSms(): bool
    {
        return $this->priority() === Priority::CRITICAL
            || $this->isFinancial()
            || $this->isSecurity();
    }

    /** Money or metal moves, or is about to, with immediate consequences. */
    public function isFinancial(): bool
    {
        return in_array($this, [
            self::PAYMENT_REQUIRED, self::PAYMENT_DECLARED, self::PAYMENT_CONFIRMED,
            self::GOLD_TRANSFERRED, self::SETTLEMENT_DUE_SOON, self::SETTLEMENT_OVERDUE,
            self::SETTLEMENT_DEFAULTED, self::NETTING_PROPOSED,
            self::LOW_GOLD_BALANCE, self::LOW_RIAL_BALANCE,
            self::VAULT_DEPOSIT_DONE, self::VAULT_WITHDRAWAL_APPROVED,
            self::ASSAY_VARIANCE, self::RFQ_ACCEPTED, self::OTC_OFFER_ACCEPTED,
        ], true);
    }

    /** Account integrity: someone got in, or a licence to trade lapsed. */
    public function isSecurity(): bool
    {
        return in_array($this, [
            self::NEW_LOGIN, self::ACCOUNT_RESTRICTED,
            self::KYC_APPROVED, self::KYC_INFO_REQUIRED, self::KYC_REJECTED,
            self::LICENSE_EXPIRING_10, self::LICENSE_EXPIRED,
        ], true);
    }

    public function titleTemplate(): string
    {
        return match ($this) {
            self::ORDER_PLACED => 'ثبت سفارش',
            self::ORDER_FILLED => 'اجرای سفارش',
            self::ORDER_PARTIAL => 'اجرای جزئی سفارش',
            self::ORDER_CANCELLED => 'لغو سفارش',
            self::ORDER_EXPIRED => 'انقضای سفارش',
            self::ORDER_REJECTED => 'رد سفارش',
            self::OTC_OFFER_RECEIVED => 'پیشنهاد جدید',
            self::OTC_OFFER_ACCEPTED => 'پذیرش پیشنهاد',
            self::RFQ_RECEIVED => 'درخواست قیمت جدید',
            self::RFQ_QUOTED => 'پیشنهاد برای درخواست شما',
            self::RFQ_ACCEPTED => 'پیشنهاد شما پذیرفته شد',
            self::RFQ_EXPIRING => 'انقضای نزدیک درخواست قیمت',

            self::SETTLEMENT_OPENED => 'آغاز تسویه',
            self::PAYMENT_REQUIRED => 'نیاز به پرداخت',
            self::PAYMENT_DECLARED => 'اعلام پرداخت طرف مقابل',
            self::PAYMENT_CONFIRMED => 'تأیید دریافت وجه',
            self::GOLD_TRANSFERRED => 'انتقال طلا',
            self::SETTLEMENT_COMPLETED => 'تکمیل تسویه',
            self::SETTLEMENT_DUE_SOON => 'نزدیک شدن مهلت تسویه',
            self::SETTLEMENT_OVERDUE => 'گذشتن مهلت تسویه',
            self::SETTLEMENT_DEFAULTED => 'نکول تسویه',
            self::NETTING_PROPOSED => 'پیشنهاد تهاتر',
            self::NETTING_EXECUTED => 'اجرای تهاتر',

            self::GOLD_RESERVED => 'رزرو طلا',
            self::GOLD_RELEASED => 'آزادسازی رزرو',
            self::LOW_GOLD_BALANCE => 'کمبود موجودی طلا',
            self::LOW_RIAL_BALANCE => 'کمبود موجودی ریالی',
            self::VAULT_DEPOSIT_DONE => 'ثبت سپرده خزانه',
            self::VAULT_WITHDRAWAL_APPROVED => 'تأیید برداشت از خزانه',
            self::ASSAY_COMPLETED => 'تکمیل ری‌گیری',
            self::ASSAY_VARIANCE => 'مغایرت عیار',

            self::KYC_APPROVED => 'تأیید حساب',
            self::KYC_INFO_REQUIRED => 'نیاز به مدارک تکمیلی',
            self::KYC_REJECTED => 'رد درخواست عضویت',
            self::LICENSE_EXPIRING_30 => 'انقضای مجوز تا ۳۰ روز دیگر',
            self::LICENSE_EXPIRING_10 => 'انقضای مجوز تا ۱۰ روز دیگر',
            self::LICENSE_EXPIRED => 'انقضای مجوز',
            self::LIMIT_INCREASED => 'افزایش سقف معاملاتی',
            self::ACCOUNT_RESTRICTED => 'محدودیت حساب',
            self::NEW_LOGIN => 'ورود جدید',
            self::TIER_UPGRADED => 'ارتقای سطح تأیید',

            self::DISPUTE_OPENED_AGAINST => 'ثبت اختلاف علیه شما',
            self::DISPUTE_REPLY_DUE => 'مهلت پاسخ به اختلاف',
            self::DISPUTE_MESSAGE => 'پیام جدید در پرونده اختلاف',
            self::DISPUTE_RESOLVED => 'صدور رأی پرونده اختلاف',
        };
    }

    public function bodyTemplate(): string
    {
        return match ($this) {
            self::ORDER_PLACED => 'سفارش :side :weight گرم ثبت شد',
            self::ORDER_FILLED => 'سفارش شما اجرا شد — :weight گرم @ :price',
            self::ORDER_PARTIAL => 'سفارش شما جزئاً اجرا شد — :filled از :weight گرم',
            self::ORDER_CANCELLED => 'سفارش شما لغو شد',
            self::ORDER_EXPIRED => 'سفارش شما منقضی شد',
            self::ORDER_REJECTED => 'سفارش رد شد — :reason',
            self::OTC_OFFER_RECEIVED => 'پیشنهاد جدید از :counterparty',
            self::OTC_OFFER_ACCEPTED => 'پیشنهاد شما پذیرفته شد',
            self::RFQ_RECEIVED => 'درخواست قیمت :weight گرم دریافت شد',
            self::RFQ_QUOTED => ':count پیشنهاد برای درخواست شما رسید',
            self::RFQ_ACCEPTED => 'پیشنهاد شما پذیرفته شد — :weight گرم @ :price',
            self::RFQ_EXPIRING => ':minutes دقیقه تا انقضای درخواست قیمت',

            self::SETTLEMENT_OPENED => 'تسویه :reference آغاز شد — مهلت :deadline',
            self::PAYMENT_REQUIRED => 'پرداخت :amount ریال تا :deadline',
            self::PAYMENT_DECLARED => 'طرف مقابل اعلام پرداخت کرد — تأیید کنید',
            self::PAYMENT_CONFIRMED => 'دریافت وجه تأیید شد',
            self::GOLD_TRANSFERRED => ':weight گرم به حساب شما منتقل شد',
            self::SETTLEMENT_COMPLETED => 'تسویه :reference کامل شد',
            self::SETTLEMENT_DUE_SOON => ':hours ساعت تا مهلت تسویه',
            self::SETTLEMENT_OVERDUE => 'مهلت تسویه :reference گذشت',
            self::SETTLEMENT_DEFAULTED => 'تسویه :reference نکول شد',
            self::NETTING_PROPOSED => 'پیشنهاد تهاتر — :obligations تعهد به :transfers انتقال',
            self::NETTING_EXECUTED => 'تهاتر اجرا شد',

            self::GOLD_RESERVED => ':weight گرم طلای شما رزرو شد',
            self::GOLD_RELEASED => 'رزرو :weight گرم آزاد شد',
            self::LOW_GOLD_BALANCE => 'موجودی طلای شما زیر حد هشدار است',
            self::LOW_RIAL_BALANCE => 'موجودی ریالی شما زیر حد هشدار است',
            self::VAULT_DEPOSIT_DONE => ':weight گرم به خزانه اضافه شد',
            self::VAULT_WITHDRAWAL_APPROVED => 'درخواست برداشت شما تأیید شد',
            self::ASSAY_COMPLETED => 'ری‌گیری تکمیل شد — عیار :purity',
            self::ASSAY_VARIANCE => 'عیار متفاوت از اعلامی — :actual به‌جای :declared',

            self::KYC_APPROVED => 'حساب شما تأیید و فعال شد',
            self::KYC_INFO_REQUIRED => 'مدارک تکمیلی نیاز است',
            self::KYC_REJECTED => 'درخواست عضویت رد شد',
            self::LICENSE_EXPIRING_30 => 'مجوز شما ۳۰ روز دیگر منقضی می‌شود',
            self::LICENSE_EXPIRING_10 => 'مجوز شما ۱۰ روز دیگر منقضی می‌شود',
            self::LICENSE_EXPIRED => 'مجوز شما منقضی شد — معامله متوقف است',
            self::LIMIT_INCREASED => 'سقف معاملاتی شما افزایش یافت',
            self::ACCOUNT_RESTRICTED => 'حساب شما محدود شد',
            self::NEW_LOGIN => 'ورود جدید از دستگاه ناشناس',
            self::TIER_UPGRADED => 'سطح شما به :tier ارتقا یافت',

            self::DISPUTE_OPENED_AGAINST => 'اختلاف علیه شما ثبت شد — مهلت پاسخ :hours ساعت',
            self::DISPUTE_REPLY_DUE => ':hours ساعت تا پایان مهلت پاسخ',
            self::DISPUTE_MESSAGE => 'پیام جدید در پرونده اختلاف :reference',
            self::DISPUTE_RESOLVED => 'رأی پرونده :reference صادر شد',
        };
    }

    /**
     * Wording for an aggregate notification (§15.4 rule 3), e.g. "۷ سفارش شما
     * اجرا شد" instead of seven identical lines.
     */
    public function aggregateBody(int $count): string
    {
        $subject = match ($this->category()) {
            Category::TRADING => 'اعلان معاملاتی',
            Category::SETTLEMENT => 'اعلان تسویه',
            Category::ASSET => 'اعلان دارایی',
            Category::ACCOUNT => 'اعلان حساب',
            Category::DISPUTE => 'اعلان اختلاف',
        };

        return sprintf('%d %s هم‌نوع: %s', $count, $subject, $this->titleTemplate());
    }

    public function aggregateTitle(int $count): string
    {
        return sprintf('%d اعلان تجمیع‌شده', $count);
    }
}
