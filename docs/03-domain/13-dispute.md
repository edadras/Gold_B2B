<div dir="rtl">

# ۱۳. مدیریت اختلاف (Dispute)

## ۱۳.۱ چرا این ماژول ضروری است

در بازار واقعی اختلاف **حتماً** پیش می‌آید. اگر سامانه مسیر ساختاریافته
برای آن نداشته باشد:
- اختلاف به خارج از سامانه منتقل می‌شود
- اعتماد به سامانه از بین می‌رود
- داده‌ای برای بهبود سیستم تولید نمی‌شود

---

## ۱۳.۲ انواع اختلاف

| کد | نوع | شرح | مدرک لازم |
|---|---|---|---|
| `WEIGHT_MISMATCH` | اختلاف وزن | وزن تحویلی با ثبت‌شده مغایر است | ویدئوی وزن‌کشی، رسید ترازو |
| `PURITY_MISMATCH` | اختلاف عیار | عیار واقعی کمتر از اعلامی | گواهی ری‌گیری مستقل |
| `AMOUNT_MISMATCH` | اختلاف مبلغ | مبلغ محاسبه‌شده اشتباه است | محاسبه، رسید بانکی |
| `PAYMENT_NOT_RECEIVED` | عدم دریافت وجه | خریدار می‌گوید پرداخته، فروشنده می‌گوید نگرفته | صورت‌حساب بانکی |
| `PAYMENT_NOT_MADE` | عدم پرداخت | خریدار پرداخت نکرده | — |
| `DELIVERY_NOT_MADE` | عدم تحویل | طلا تحویل نشده | — |
| `DELIVERY_INCOMPLETE` | تحویل ناقص | تعداد/وزن کمتر | عکس، رسید |
| `HALLMARK_MISMATCH` | مغایرت انگ | انگ با گواهی نمی‌خواند | عکس قطعه و گواهی |
| `OWNERSHIP_CLAIM` | ادعای مالکیت | شخص ثالث ادعای مالکیت دارد | مستندات حقوقی |
| `QUALITY_DEFECT` | عیب کیفی | ناخالصی، شکل نامناسب | عکس، گزارش آزمایشگاه |
| `SETTLEMENT_DELAY` | تأخیر تسویه | مهلت گذشته | — |
| `UNAUTHORIZED_TRADE` | معامله غیرمجاز | کاربر بدون اختیار معامله کرده | — |
| `SYSTEM_ERROR` | خطای سامانه | محاسبه یا ثبت اشتباه سیستم | — |

---

## ۱۳.۳ ماشین حالت

</div>

```
      ┌──────────┐
      │  OPENED  │  معترض ثبت کرد
      └────┬─────┘
           │ اعلان فوری به طرف مقابل
           │ قفل خودکار مبلغ/وزن مورد اختلاف
           ▼
   ┌────────────────┐
   │ AWAITING_REPLY │  مهلت ۲۴ ساعت
   └────────┬───────┘
            │
   ┌────────┼────────┬─────────────────┐
   ▼        ▼        ▼                 ▼
┌─────────┐┌────────┐┌──────────┐  ┌─────────────┐
│ACCEPTED ││DISPUTED││ NO_REPLY │  │  WITHDRAWN  │
│by other ││        ││ (timeout)│  │ by claimant │
└────┬────┘└───┬────┘└─────┬────┘  └─────────────┘
     │         │            │
     │         ▼            │
     │  ┌─────────────┐     │
     │  │ NEGOTIATION │     │  مهلت ۴۸ ساعت
     │  └──────┬──────┘     │  برای توافق مستقیم
     │         │            │
     │    ┌────┴────┐       │
     │    ▼         ▼       │
     │ توافق   عدم توافق    │
     │    │         │       │
     │    │         ▼       ▼
     │    │  ┌──────────────────┐
     │    │  │  UNDER_MEDIATION │  اپراتور رسیدگی می‌کند
     │    │  └────────┬─────────┘
     │    │           │
     │    │      ┌────┴────┐
     │    │      ▼         ▼
     │    │  ┌────────┐ ┌───────────────┐
     │    │  │ نیاز به│ │  رأی صادر شد   │
     │    │  │ ری‌گیری│ └───────┬───────┘
     │    │  │ ثالث   │         │
     │    │  └───┬────┘         │
     │    │      └──────────────┤
     │    │                     │
     └────┴─────────────────────┤
                                ▼
                       ┌────────────────┐
                       │    RESOLVED    │
                       └────────┬───────┘
                                │ اجرای رأی
                                ▼
                       ┌────────────────┐
                       │    EXECUTED    │  نهایی
                       └────────────────┘
```

<div dir="rtl">

---

## ۱۳.۴ اثر بلافاصله ثبت اختلاف

</div>

```
ثبت اختلاف
      │
      ├──► توقف Settlement مربوطه (اگر هنوز کامل نشده)
      │
      ├──► قفل مبلغ/وزن مورد ادعا در دفتر هر دو طرف
      │      LedgerEntry: DISPUTE_HOLD
      │      AVAILABLE ► IN_DISPUTE
      │
      ├──► اگر lot مشخصی درگیر است:
      │      lot.status ► ON_HOLD
      │      غیرقابل معامله، غیرقابل خروج از خزانه
      │
      ├──► اعلان فوری به طرف مقابل (Push + SMS)
      │
      ├──► ثبت در پروفایل ریسک هر دو طرف (موقت)
      │
      └──► ایجاد پرونده با شماره یکتا و تایم‌لاین
```

<div dir="rtl">

**مبلغ قفل‌شده = فقط مبلغ مورد ادعا**، نه کل معامله.

</div>

```
مثال:
  معامله: 500 گرم @ عیار 995
  ادعا: عیار واقعی 985 است

  اختلاف = 500 × (995 − 985) / 1000 = 5 گرم خالص
  مبلغ معادل = 5 × 78,480,000 = 392,400,000 ریال

  ► فقط 392,400,000 ریال قفل می‌شود، نه کل 39 میلیارد
```

<div dir="rtl">

---

## ۱۳.۵ مدل داده

</div>

```sql
CREATE TABLE disputes (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_number             VARCHAR(30) NOT NULL UNIQUE,   -- DSP-1404-00142
  dispute_type            VARCHAR(50) NOT NULL,

  trade_id                BIGINT UNSIGNED NULL,
  settlement_id           BIGINT UNSIGNED NULL,
  gold_lot_id             BIGINT UNSIGNED NULL,

  claimant_org_id         BIGINT UNSIGNED NOT NULL,
  respondent_org_id       BIGINT UNSIGNED NOT NULL,
  opened_by_user_id       BIGINT UNSIGNED NOT NULL,

  claim_description       TEXT NOT NULL,
  claim_gold_mg           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  claim_rial              BIGINT UNSIGNED NOT NULL DEFAULT 0,

  status                  VARCHAR(30) NOT NULL,
  priority                ENUM('LOW','NORMAL','HIGH','URGENT') NOT NULL,

  hold_gold_entry_id      BIGINT UNSIGNED NULL,
  hold_rial_entry_id      BIGINT UNSIGNED NULL,

  reply_deadline_at       TIMESTAMP NULL,
  negotiation_deadline_at TIMESTAMP NULL,

  mediator_user_id        BIGINT UNSIGNED NULL,
  decision                VARCHAR(50) NULL,
  decision_rationale      TEXT NULL,
  decided_at              TIMESTAMP NULL,
  decided_by_user_id      BIGINT UNSIGNED NULL,

  awarded_gold_mg         BIGINT NOT NULL DEFAULT 0,   -- می‌تواند منفی باشد
  awarded_rial            BIGINT NOT NULL DEFAULT 0,
  reversal_settlement_id  BIGINT UNSIGNED NULL,

  opened_at               TIMESTAMP NOT NULL,
  resolved_at             TIMESTAMP NULL,
  executed_at             TIMESTAMP NULL,

  KEY idx_claimant (claimant_org_id, status),
  KEY idx_respondent (respondent_org_id, status),
  KEY idx_status_priority (status, priority),
  KEY idx_trade (trade_id)
) ENGINE=InnoDB;

CREATE TABLE dispute_evidences (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dispute_id      BIGINT UNSIGNED NOT NULL,
  submitted_by_org_id BIGINT UNSIGNED NOT NULL,
  submitted_by_user_id BIGINT UNSIGNED NOT NULL,
  evidence_type   ENUM('DOCUMENT','PHOTO','VIDEO','ASSAY_REPORT',
                       'BANK_STATEMENT','WITNESS_STATEMENT','SYSTEM_LOG') NOT NULL,
  document_id     BIGINT UNSIGNED NULL,
  description     TEXT NOT NULL,
  file_hash       CHAR(64) NULL,
  submitted_at    TIMESTAMP NOT NULL,
  KEY idx_dispute (dispute_id)
) ENGINE=InnoDB;

CREATE TABLE dispute_timeline (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dispute_id      BIGINT UNSIGNED NOT NULL,
  actor_type      ENUM('CLAIMANT','RESPONDENT','MEDIATOR','SYSTEM') NOT NULL,
  actor_user_id   BIGINT UNSIGNED NULL,
  action          VARCHAR(100) NOT NULL,
  message         TEXT NULL,
  from_status     VARCHAR(30) NULL,
  to_status       VARCHAR(30) NULL,
  occurred_at     TIMESTAMP(6) NOT NULL,
  KEY idx_dispute_time (dispute_id, occurred_at)
) ENGINE=InnoDB;
```

<div dir="rtl">

---

## ۱۳.۶ فرآیند رسیدگی

### مرحله ۱ — پاسخ طرف مقابل (۲۴ ساعت)

</div>

```
طرف مقابل سه گزینه دارد:

  ۱) پذیرش کامل
     ► رأی خودکار به نفع معترض
     ► اجرای اصلاح
     ► بدون اثر منفی بر Reputation پاسخ‌دهنده (چون پذیرفته)

  ۲) پذیرش جزئی
     ► «قبول دارم اما مقدارش این نیست»
     ► ورود به مذاکره

  ۳) رد کامل + ارائه مدرک
     ► ورود به مذاکره
```

<div dir="rtl">

### مرحله ۲ — مذاکره مستقیم (۴۸ ساعت)

</div>

```
دو طرف در یک فضای گفت‌وگوی ثبت‌شده تلاش می‌کنند توافق کنند.

  · تمام پیام‌ها ثبت و برای اپراتور قابل مشاهده است
  · می‌توانند پیشنهاد تسویه بدهند
  · پذیرش پیشنهاد ► رأی توافقی، بدون نیاز به اپراتور

مزیت: بیشتر اختلافات در همین مرحله حل می‌شوند و
      بار اپراتور به‌شدت کاهش می‌یابد.
```

<div dir="rtl">

### مرحله ۳ — میانجی‌گری اپراتور

</div>

```
اپراتور دسترسی دارد به:

  ┌─────────────────────────────────────────────────┐
  │ · متن کامل ادعا و پاسخ                           │
  │ · تمام مدارک هر دو طرف                           │
  │ · تاریخچه معامله و تسویه                         │
  │ · گواهی‌های ری‌گیری مرتبط                        │
  │ · شجره‌نامه lot                                  │
  │ · سوابق اختلاف قبلی هر دو طرف                    │
  │ · لاگ سیستمی (اگر ادعای خطای سیستم است)          │
  │ · Reputation هر دو طرف                           │
  └─────────────────────────────────────────────────┘

ابزارهای اپراتور:
  · درخواست مدرک تکمیلی
  · درخواست ری‌گیری مستقل از آزمایشگاه ثالث
  · دعوت به جلسه (حضوری/آنلاین)
  · ارجاع به کارشناس اتحادیه
```

<div dir="rtl">

### ری‌گیری ثالث

</div>

```
در اختلاف عیار، معتبرترین راه حل:

۱) lot به آزمایشگاه TIER_1 مستقل ارسال می‌شود
   (نه آزمایشگاهی که گواهی اولیه را داده)
        │
        ▼
۲) هزینه ابتدا از سامانه، سپس از بازنده اختلاف
        │
        ▼
۳) نتیجه ری‌گیری مبنای رأی است
        │
        ▼
۴) گواهی جدید ثبت و گواهی قبلی SUPERSEDED می‌شود
        │
        ▼
۵) اگر اختلاف واقعی بود ► بررسی آزمایشگاه اول
   (اگر تکرار شود ► کاهش سطح اعتبار آزمایشگاه)
```

<div dir="rtl">

---

## ۱۳.۷ انواع رأی

| رأی | معنی | اجرا |
|---|---|---|
| `CLAIM_UPHELD_FULL` | ادعا کاملاً وارد | برگشت کامل معامله یا جبران کامل |
| `CLAIM_UPHELD_PARTIAL` | ادعا جزئاً وارد | جبران به میزان تعیین‌شده |
| `CLAIM_REJECTED` | ادعا رد شد | آزادسازی قفل، بدون تغییر |
| `SETTLED_BY_AGREEMENT` | توافق طرفین | اجرای مفاد توافق |
| `WITHDRAWN` | معترض پس گرفت | آزادسازی قفل |
| `SPLIT_LIABILITY` | مسئولیت مشترک | تقسیم بار بین دو طرف |
| `SYSTEM_FAULT` | خطای سامانه | جبران توسط سامانه، بدون اثر بر Reputation طرفین |

### اجرای رأی

</div>

```php
public function executeDecision(Dispute $dispute): void
{
    DB::transaction(function () use ($dispute) {
        // ۱) آزادسازی قفل
        $this->ledger->releaseDisputeHold($dispute->hold_gold_entry_id);
        $this->ledger->releaseDisputeHold($dispute->hold_rial_entry_id);

        // ۲) اجرای جبران
        if ($dispute->awarded_gold_mg !== 0) {
            $this->ledger->transfer(
                fromOrgId: $dispute->awarded_gold_mg > 0
                    ? $dispute->respondent_org_id
                    : $dispute->claimant_org_id,
                toOrgId: $dispute->awarded_gold_mg > 0
                    ? $dispute->claimant_org_id
                    : $dispute->respondent_org_id,
                amount: FineWeight::fromMilligrams(abs($dispute->awarded_gold_mg)),
                ref: LedgerReference::dispute($dispute->id),
            );
        }

        if ($dispute->awarded_rial !== 0) {
            // مشابه برای ریال
        }

        // ۳) آزادسازی lot
        if ($dispute->gold_lot_id) {
            $this->custody->release($dispute->gold_lot_id);
        }

        // ۴) به‌روزرسانی وضعیت
        $dispute->update([
            'status' => 'EXECUTED',
            'executed_at' => now(),
        ]);
    });

    event(new DisputeResolved($dispute->id, $dispute->decision));
}
```

<div dir="rtl">

---

## ۱۳.۸ اثر بر Reputation

</div>

```
بازنده اختلاف:
   ├── dispute_lost_count += 1
   ├── credit_score −80
   └── اگر ۳ اختلاف بازنده در ۹۰ روز ► بازبینی ریسک

برنده اختلاف:
   └── بدون اثر مثبت (جلوگیری از انگیزه ثبت اختلاف بی‌مورد)

ادعای بی‌اساس (CLAIM_REJECTED با تشخیص سوءنیت):
   ├── frivolous_claim_count += 1
   ├── credit_score −50
   └── اگر تکرار شود ► محدودیت ثبت اختلاف

پذیرش سریع اشتباه خود:
   └── بدون اثر منفی — رفتار مطلوب تشویق می‌شود

خطای سامانه:
   └── بدون اثر بر هیچ‌کدام
```

<div dir="rtl">

---

## ۱۳.۹ رابط کاربری اختلاف

</div>

```
┌──────────────────────────────────────────────────────────────────┐
│  پرونده DSP-1404-00142                        وضعیت: در مذاکره   │
├──────────────────────────────────────────────────────────────────┤
│  نوع        : اختلاف عیار                                        │
│  معامله     : TRD-88231 (۱۴۰۴/۰۸/۰۵)                            │
│  معترض      : طلافروشی کریمی                                     │
│  طرف مقابل  : بنکداری پارس                                       │
│  ادعا       : 5.000 گرم خالص (392,400,000 ریال)                  │
│  مهلت مذاکره: ۳۱ ساعت مانده                                      │
├──────────────────────────────────────────────────────────────────┤
│  تایم‌لاین                                                        │
│                                                                  │
│  ۰۸/۰۶ ۱۰:۲۰  🔴 کریمی پرونده را باز کرد                         │
│                «عیار تحویلی ۹۸۵ است نه ۹۹۵. گواهی پیوست است.»    │
│                📎 گواهی آزمایشگاه مستقل.pdf                      │
│                                                                  │
│  ۰۸/۰۶ ۱۰:۲۰  ⚙️ سیستم: 392,400,000 ریال قفل شد                  │
│  ۰۸/۰۶ ۱۰:۲۰  ⚙️ سیستم: lot GL-00001287 در وضعیت ON_HOLD          │
│                                                                  │
│  ۰۸/۰۶ ۱۴:۴۵  🟠 پارس پاسخ داد — رد جزئی                         │
│                «گواهی ما ۹۹۵ است. حاضریم ری‌گیری ثالث بدهیم.»     │
│                📎 گواهی اولیه.pdf                                │
│                                                                  │
│  ۰۸/۰۶ ۱۵:۱۰  💬 کریمی: «موافقم، آزمایشگاه شماره ۷»               │
│  ۰۸/۰۶ ۱۵:۳۰  💬 پارس: «قبول. هزینه با بازنده.»                   │
│                                                                  │
│  ۰۸/۰۶ ۱۶:۰۰  ⚙️ سیستم: درخواست ری‌گیری ثالث ثبت شد               │
├──────────────────────────────────────────────────────────────────┤
│  [ارسال پیام]  [افزودن مدرک]  [پیشنهاد تسویه]  [درخواست میانجی]  │
└──────────────────────────────────────────────────────────────────┘
```

<div dir="rtl">

---

## ۱۳.۱۰ شاخص‌های کلیدی

| شاخص | هدف |
|---|---|
| نرخ اختلاف (اختلاف/معامله) | < ۰٫۲٪ |
| نرخ حل در مرحله پاسخ | > ۵۰٪ |
| نرخ حل در مرحله مذاکره | > ۳۰٪ |
| نرخ نیاز به میانجی | < ۲۰٪ |
| میانگین زمان حل | < ۷۲ ساعت |
| نرخ اختلاف عیار | باید در طول زمان کاهش یابد (اثر گواهی) |

**تحلیل ریشه‌ای:** هر ماه، الگوی اختلافات بررسی می‌شود:
- اگر اختلاف عیار زیاد است ► بررسی آزمایشگاه‌های خاص
- اگر اختلاف پرداخت زیاد است ► نیاز به اتصال بانکی
- اگر اختلاف با یک عضو خاص زیاد است ► بازبینی ریسک آن عضو

</div>
