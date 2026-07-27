<div dir="rtl">

# ۱۴. اعتبار و شهرت (Reputation)

## ۱۴.۱ هدف

جایگزین کردن «شناخت شخصی» با یک سنجه **قابل اندازه‌گیری و قابل انتقال**.

</div>

```
بازار امروز:
   «من فلانی رو می‌شناسم، بهش اعتماد دارم»
   ► غیرقابل انتقال به نفر سوم
   ► وابسته به شبکه شخصی
   ► مانع ورود اعضای جدید

با Reputation:
   «این عضو ۱۲,۸۳۰ معامله با نرخ تسویه ۹۹.۸٪ دارد»
   ► قابل اتکا برای هر عضوی
   ► عضو جدید می‌تواند اعتبار بسازد
   ► شبکه بزرگ‌تر و نقدشوندگی بیشتر
```

<div dir="rtl">

---

## ۱۴.۲ تفاوت با Credit Score

| | Credit Score (Risk) | Reputation |
|---|---|---|
| مخاطب | داخلی — سامانه | عمومی — سایر اعضا |
| هدف | تعیین سقف و مجوز | تصمیم‌گیری اعضا |
| نمایش | فقط به ادمین | به همه اعضا |
| محتوا | شامل داده حساس (AML) | فقط داده عملکردی |
| تأثیر | مسدودسازی معامله | ترجیح در RFQ |

**قاعده سخت:** هیچ داده AML یا محرمانه‌ای نباید در Reputation عمومی
نشت کند. `Tipping-off` نقض جدی است.

---

## ۱۴.۳ سنجه‌های عمومی

</div>

```
┌────────────────────────────────────────────────────────┐
│  بنکداری پارس                          عضو از ۱۴۰۲/۰۵  │
│  ─────────────────────────────────────────────────────  │
│                                                        │
│              🏅 سطح تأیید: طلایی                        │
│                                                        │
│  معاملات تکمیل‌شده        ۱۲,۸۳۰                       │
│  حجم کل                   ۴,۸۲۰ کیلوگرم                │
│  تسویه به‌موقع            ۹۹.۸٪   ████████████████░    │
│  نرخ اختلاف               ۰.۰۴٪   ░░░░░░░░░░░░░░░░░    │
│  میانگین زمان تسویه       ۲۳ دقیقه                     │
│  طرف‌حساب‌های متمایز       ۱۴۲ عضو                      │
│  نرخ پاسخ به RFQ          ۸۷٪                          │
│  میانگین زمان پاسخ RFQ    ۴ دقیقه                      │
│                                                        │
│  ─────────────────────────────────────────────────────  │
│  آخرین فعالیت: امروز                                   │
│  وضعیت: فعال ✅                                         │
└────────────────────────────────────────────────────────┘
```

<div dir="rtl">

### فهرست کامل سنجه‌ها

| سنجه | محاسبه | نمایش |
|---|---|---|
| `total_trades` | تعداد معاملات تکمیل‌شده | عدد |
| `total_volume_mg` | مجموع وزن خالص | کیلوگرم |
| `on_time_settlement_rate` | به‌موقع / کل | درصد |
| `dispute_rate` | اختلاف بازنده / کل معامله | درصد |
| `avg_settlement_minutes` | میانگین زمان از معامله تا تسویه | دقیقه |
| `distinct_counterparties` | تعداد طرف‌حساب یکتا | عدد |
| `rfq_response_rate` | پاسخ / دریافت | درصد |
| `rfq_avg_response_minutes` | میانگین زمان پاسخ | دقیقه |
| `quote_fill_rate` | پیشنهادهایی که اجرا شدند / پذیرفته‌شده | درصد |
| `member_since` | تاریخ فعال‌سازی | تاریخ |
| `last_active_at` | آخرین فعالیت | نسبی |
| `verification_tier` | سطح تأیید | نشان |

---

## ۱۴.۴ سطوح تأیید (Verification Tier)

</div>

```
┌──────────┬────────────────────────────────────────────────┐
│ 🥉 برنزی │ · KYC پایه تأیید شده                            │
│          │ · حداقل ۱ معامله                                │
│          │ · دسترسی: OTC، RFQ                              │
├──────────┼────────────────────────────────────────────────┤
│ 🥈 نقره‌ای│ · KYC کامل + حساب بانکی تأییدشده                │
│          │ · حداقل ۵۰ معامله تکمیل‌شده                     │
│          │ · حداقل ۹۰ روز عضویت                            │
│          │ · نرخ تسویه به‌موقع > ۹۵٪                        │
│          │ · دسترسی: + Order Book                          │
├──────────┼────────────────────────────────────────────────┤
│ 🥇 طلایی │ · همه موارد نقره‌ای                              │
│          │ · حداقل ۵۰۰ معامله                              │
│          │ · حداقل ۱ سال عضویت                             │
│          │ · نرخ تسویه به‌موقع > ۹۹٪                        │
│          │ · نرخ اختلاف < ۰.۱٪                              │
│          │ · صفر نکول                                      │
│          │ · دسترسی: + سقف بالا، + تسویه T+1، + حساب باز   │
├──────────┼────────────────────────────────────────────────┤
│ 💎 ممتاز │ · همه موارد طلایی                                │
│          │ · حداقل ۵۰۰۰ معامله                             │
│          │ · بازارساز فعال (Maker بیش از ۵۰٪ حجم)          │
│          │ · دسترسی: + کارمزد ترجیحی، + API پیشرفته        │
└──────────┴────────────────────────────────────────────────┘
```

<div dir="rtl">

### قواعد تغییر سطح

</div>

```
ارتقا:
  · بررسی خودکار روزانه
  · اطلاع‌رسانی و تبریک
  · اثر فوری بر دسترسی‌ها

تنزل:
  · فقط با تصمیم COMPLIANCE_OFFICER، نه خودکار
  · محرک‌ها: نکول، اختلاف مکرر، تعلیق
  · اطلاع‌رسانی با ذکر دلیل و مسیر بازیابی
  · دوره انتظار ۹۰ روزه برای ارتقای مجدد

⚠️ تنزل خودکار به دلیل یک اشتباه، اعتماد به سامانه را از بین می‌برد.
   همیشه بررسی انسانی لازم است.
```

<div dir="rtl">

---

## ۱۴.۵ مدل داده

</div>

```sql
CREATE TABLE reputation_stats (
  organization_id             BIGINT UNSIGNED PRIMARY KEY,

  total_trades                INT UNSIGNED NOT NULL DEFAULT 0,
  total_volume_mg             BIGINT UNSIGNED NOT NULL DEFAULT 0,

  settlements_total           INT UNSIGNED NOT NULL DEFAULT 0,
  settlements_on_time         INT UNSIGNED NOT NULL DEFAULT 0,
  settlements_late            INT UNSIGNED NOT NULL DEFAULT 0,
  settlements_defaulted       INT UNSIGNED NOT NULL DEFAULT 0,
  total_settlement_minutes    BIGINT UNSIGNED NOT NULL DEFAULT 0,

  disputes_involved           INT UNSIGNED NOT NULL DEFAULT 0,
  disputes_lost               INT UNSIGNED NOT NULL DEFAULT 0,
  disputes_frivolous          INT UNSIGNED NOT NULL DEFAULT 0,

  distinct_counterparties     INT UNSIGNED NOT NULL DEFAULT 0,

  rfq_received                INT UNSIGNED NOT NULL DEFAULT 0,
  rfq_responded               INT UNSIGNED NOT NULL DEFAULT 0,
  rfq_total_response_minutes  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  quotes_accepted             INT UNSIGNED NOT NULL DEFAULT 0,
  quotes_filled               INT UNSIGNED NOT NULL DEFAULT 0,

  maker_volume_mg             BIGINT UNSIGNED NOT NULL DEFAULT 0,
  taker_volume_mg             BIGINT UNSIGNED NOT NULL DEFAULT 0,

  verification_tier           ENUM('BRONZE','SILVER','GOLD','PLATINUM') NOT NULL,
  tier_achieved_at            TIMESTAMP NULL,

  member_since                TIMESTAMP NOT NULL,
  last_active_at              TIMESTAMP NULL,
  updated_at                  TIMESTAMP NOT NULL
) ENGINE=InnoDB;
```

<div dir="rtl">

### آمار دوره‌ای (برای نمایش روند)

</div>

```sql
CREATE TABLE reputation_periods (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id     BIGINT UNSIGNED NOT NULL,
  period_type         ENUM('DAY','WEEK','MONTH') NOT NULL,
  period_start        DATE NOT NULL,
  trades              INT UNSIGNED NOT NULL DEFAULT 0,
  volume_mg           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  on_time_rate_bps    INT UNSIGNED NULL,
  disputes            INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_org_period (organization_id, period_type, period_start)
) ENGINE=InnoDB;
```

<div dir="rtl">

---

## ۱۴.۶ به‌روزرسانی آمار

</div>

```php
// Listener روی SettlementCompleted

public function handle(SettlementCompleted $event): void
{
    foreach ([$event->buyerOrgId, $event->sellerOrgId] as $orgId) {
        $wasOnTime = $event->completedAt <= $event->deadlineAt;
        $minutes = $event->executedAt->diffInMinutes($event->completedAt);

        DB::statement("
            INSERT INTO reputation_stats
                (organization_id, total_trades, total_volume_mg,
                 settlements_total, settlements_on_time, settlements_late,
                 total_settlement_minutes, member_since, last_active_at, updated_at)
            VALUES (?, 1, ?, 1, ?, ?, ?, NOW(), NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                total_trades             = total_trades + 1,
                total_volume_mg          = total_volume_mg + VALUES(total_volume_mg),
                settlements_total        = settlements_total + 1,
                settlements_on_time      = settlements_on_time + VALUES(settlements_on_time),
                settlements_late         = settlements_late + VALUES(settlements_late),
                total_settlement_minutes = total_settlement_minutes + VALUES(total_settlement_minutes),
                last_active_at           = NOW(),
                updated_at               = NOW()
        ", [$orgId, $event->fineWeightMg, $wasOnTime ? 1 : 0, $wasOnTime ? 0 : 1, $minutes]);
    }
}
```

<div dir="rtl">

**نکته:** `distinct_counterparties` نمی‌تواند با `+1` ساده محاسبه شود.
این مقدار در job شبانه از روی `counterparty_relations` بازمحاسبه می‌شود.

---

## ۱۴.۷ نمایش در نقاط تصمیم

### در RFQ

</div>

```
┌───────────────────────────────────────────────────────────┐
│  پیشنهادهای دریافتی — RFQ-00001204                         │
├───────────────────────────────────────────────────────────┤
│  🥇 بنکداری پارس         5,000g @ 78,480,000              │
│     ۱۲,۸۳۰ معامله · تسویه ۹۹.۸٪ · اختلاف ۰.۰۴٪            │
│     [پذیرش]                                               │
├───────────────────────────────────────────────────────────┤
│  🥈 زرگری اصفهان         5,000g @ 78,470,000  ◄ ارزان‌تر  │
│     ۳۴۲ معامله · تسویه ۹۶.۲٪ · اختلاف ۰.۹٪                │
│     ⚠️ نرخ اختلاف بالاتر از میانگین بازار                  │
│     [پذیرش]                                               │
├───────────────────────────────────────────────────────────┤
│  🥉 آبشده یزد            3,000g @ 78,465,000  (جزئی)      │
│     ۲۸ معامله · عضو جدید (۴۵ روز)                         │
│     [پذیرش]                                               │
└───────────────────────────────────────────────────────────┘
```

<div dir="rtl">

**اصل طراحی:** قیمت ارزان‌تر همیشه بهتر نیست. سامانه باید اطلاعات کافی
برای تصمیم آگاهانه بدهد، اما تصمیم را نگیرد.

### در OTC

</div>

```
پیشنهاد جدید از: زرگری تبریز 🥈
┌────────────────────────────────────────────┐
│ فروش 500g @ 78,500,000                     │
│ ─────────────────────────────────────────  │
│ سابقه شما با این عضو:                       │
│   ۱۲ معامله قبلی · همه تسویه به‌موقع ✅     │
│   آخرین معامله: ۳ روز پیش                  │
│                                            │
│ عملکرد کلی این عضو:                         │
│   ۸۴۲ معامله · تسویه ۹۸.۱٪                 │
│ ─────────────────────────────────────────  │
│ [پذیرش]  [پیشنهاد متقابل]  [رد]            │
└────────────────────────────────────────────┘
```

<div dir="rtl">

---

## ۱۴.۸ ضدبازی (Anti-Gaming)

سنجه‌ها قابل دستکاری هستند اگر طراحی نشوند:

</div>

```
حمله ۱ — افزایش مصنوعی تعداد معامله
   دو عضو همدست، معاملات کوچک پی‌درپی با هم
   ⛔ دفاع:
      · وزن‌دهی بر اساس حجم، نه فقط تعداد
      · شمارش distinct_counterparties
      · قاعده AML برای معاملات رفت‌وبرگشتی
      · حداقل حجم برای احتساب در آمار

حمله ۲ — پنهان کردن نکول با تسویه لحظه آخر
   ⛔ دفاع:
      · ثبت avg_settlement_minutes
      · نمایش توزیع، نه فقط میانگین

حمله ۳ — عدم پذیرش معاملات ریسکی برای حفظ آمار
   ⛔ دفاع:
      · نرخ پاسخ به RFQ هم نمایش داده می‌شود
      · اجتناب از معامله هم قابل مشاهده است

حمله ۴ — ثبت‌نام مجدد پس از خراب شدن آمار
   ⛔ دفاع:
      · KYC با کد ملی/شناسه ملی — تشخیص تکرار
      · تشخیص ارتباط سازمان‌ها (آدرس، حساب بانکی، نماینده مشترک)
      · عضو جدید همیشه از صفر شروع می‌کند و برچسب «جدید» دارد
```

<div dir="rtl">

---

## ۱۴.۹ حریم خصوصی

</div>

```
❌ هرگز نمایش داده نمی‌شود:
   · مانده دارایی
   · طرف‌حساب‌های خاص («با چه کسانی معامله می‌کند»)
   · قیمت‌های معاملات مشخص
   · پرچم‌های AML
   · دلیل تعلیق
   · اطلاعات KYC
   · Credit Score داخلی

✅ نمایش داده می‌شود:
   · آمار تجمعی عملکرد
   · سطح تأیید
   · وضعیت فعال/غیرفعال
   · سابقه معامله با «من» (فقط برای خودم)
```

<div dir="rtl">

### حق اعتراض عضو

عضو باید بتواند:
- آمار خودش را دقیقاً ببیند
- در صورت خطای محاسبه اعتراض کند
- توضیح ببیند که هر عدد از کجا آمده

</div>

```
┌──────────────────────────────────────────────────────┐
│  نرخ تسویه به‌موقع من: ۹۶.۲٪                          │
│                                                      │
│  ۳۴۲ تسویه کل                                        │
│  ۳۲۹ به‌موقع                                          │
│   ۱۳ با تأخیر  [مشاهده فهرست]                        │
│                                                      │
│  💡 اگر ۸ تسویه بعدی به‌موقع باشد، به ۹۷٪ می‌رسید     │
│                                                      │
│  اعتراض به یک مورد؟ [ثبت اعتراض]                     │
└──────────────────────────────────────────────────────┘
```

</div>
