<div dir="rtl">

# ۱۱. ریسک، اعتبار و محدودیت‌ها

## ۱۱.۱ پروفایل ریسک عضو

</div>

```
RiskProfile
─────────────────────────────────────────────────────
  organization_id       184
  risk_level            LOW | MEDIUM | HIGH | CRITICAL
  credit_score          0 – 1000

  ── سقف‌های معاملاتی ────────────────────────────────
  max_order_mg          سقف هر سفارش
  max_daily_volume_mg   سقف حجم روزانه
  max_open_orders       تعداد سفارش باز همزمان
  max_open_exposure_mg  مجموع تعهدات تسویه‌نشده (طلا)
  max_open_exposure_rial مجموع تعهدات تسویه‌نشده (ریال)

  ── سقف اعتباری ────────────────────────────────────
  unsecured_credit_mg   اعتبار بدون وثیقه
  collateral_value      ارزش وثیقه سپرده‌شده

  ── تسویه ──────────────────────────────────────────
  allowed_settlement_types  [T0] یا [T0, T1] یا [T0,T1,ON_ACCOUNT]
  max_settlement_days       0 = فقط T0

  ── وضعیت ──────────────────────────────────────────
  is_trading_allowed
  restriction_reason
  reviewed_at, reviewed_by, next_review_at
```

<div dir="rtl">

### سطوح ریسک و سقف‌های پیش‌فرض

| سطح | سقف سفارش | سقف روزانه | تسویه مجاز | اعتبار بدون وثیقه |
|---|---|---|---|---|
| `LOW` | ۱۰ کیلو | ۵۰ کیلو | T0, T1, حساب باز | ۵ کیلو |
| `MEDIUM` | ۲ کیلو | ۱۰ کیلو | T0, T1 | ۵۰۰ گرم |
| `HIGH` | ۵۰۰ گرم | ۲ کیلو | فقط T0 | ۰ |
| `CRITICAL` | ۰ | ۰ | — | ۰ |

**عضو تازه‌وارد** همیشه با `MEDIUM` شروع می‌کند، نه `LOW`.

---

## ۱۱.۲ امتیاز اعتباری

</div>

```
credit_score = وزن‌دار مجموع مؤلفه‌ها  (0 – 1000)

┌────────────────────────────┬──────┬────────────────────────────┐
│ مؤلفه                       │ وزن  │ محاسبه                     │
├────────────────────────────┼──────┼────────────────────────────┤
│ نرخ تسویه به‌موقع           │ ۳۵٪  │ on_time / total × 350      │
│ سابقه فعالیت               │ ۱۵٪  │ min(months/24, 1) × 150    │
│ حجم معاملات                │ ۱۵٪  │ log-scale × 150            │
│ نرخ اختلاف (معکوس)         │ ۱۵٪  │ (1 − dispute_rate) × 150   │
│ کامل بودن KYC              │ ۱۰٪  │ verified_items/total × 100 │
│ تنوع طرف‌حساب              │  ۵٪  │ distinct_cp / 20 × 50      │
│ عدم پرچم AML               │  ۵٪  │ flags == 0 ? 50 : 0        │
└────────────────────────────┴──────┴────────────────────────────┘

نگاشت به سطح ریسک:
   800 – 1000  ► LOW
   500 –  799  ► MEDIUM
   200 –  499  ► HIGH
     0 –  199  ► CRITICAL
```

<div dir="rtl">

### رویدادهای کاهنده فوری

</div>

```
رویداد                              اثر
────────────────────────────────────────────────────
تسویه با تأخیر < ۲ ساعت              −10
تسویه با تأخیر ۲–۲۴ ساعت             −40
نکول (DEFAULTED)                    −250 + سطح ► HIGH
اختلاف بازنده                        −80
پرچم AML با شدت بالا                 −150 + سطح ► HIGH
انقضای مجوز                          سطح ► RESTRICTED
تلاش برای Self-Trade                 −50 + ثبت AML
پیشنهاد RFQ که نتوانست اجرا کند      −20
```

<div dir="rtl">

### بازیابی امتیاز

</div>

```
· هر معامله تسویه‌شده به‌موقع: +2 (تا سقف بازیابی)
· امتیاز کاهش‌یافته به‌صورت خطی طی ۹۰ روز بازیابی می‌شود
· نکول: بازیابی فقط پس از ۱۸۰ روز بدون تخلف
· تغییر سطح به سمت بالا نیازمند تأیید Compliance است، نه خودکار
```

<div dir="rtl">

---

## ۱۱.۳ محاسبه تعهدات باز (Open Exposure)

</div>

```
تعهدات باز طلایی یک عضو =
    Σ (طلایی که باید تحویل دهد و هنوز نداده)
  + Σ (طلای رزروشده بابت سفارش‌های فروش باز)

تعهدات باز ریالی =
    Σ (ریالی که باید بپردازد و هنوز نپرداخته)
  + Σ (ریال رزروشده بابت سفارش‌های خرید باز)
```

<div dir="rtl">

</div>

```sql
-- تعهدات طلایی باز
SELECT
    COALESCE(SUM(s.fine_weight_mg), 0) AS settlement_exposure
FROM settlements s
WHERE s.gold_deliverer_org_id = ?
  AND s.status NOT IN ('SETTLED','COMPLETED','CANCELLED','REVERSED')

UNION ALL

SELECT
    COALESCE(SUM(o.quantity_mg - o.filled_mg), 0) AS order_exposure
FROM orders o
WHERE o.organization_id = ?
  AND o.side = 'SELL'
  AND o.status IN ('OPEN','PARTIALLY_FILLED');
```

<div dir="rtl">

---

## ۱۱.۴ بررسی پیش از معامله (Pre-Trade Risk Check)

</div>

```php
namespace App\Modules\Risk\Application;

final class RiskGuard implements RiskGuardInterface
{
    public function assertTradeAllowed(TradeIntent $intent): void
    {
        $profile = $this->profiles->forOrganization($intent->organizationId);

        // ۱) وضعیت کلی
        if (! $profile->is_trading_allowed) {
            throw new TradingNotAllowedException($profile->restriction_reason);
        }

        // ۲) وضعیت سازمان
        $org = $this->organizations->find($intent->organizationId);
        if ($org->status !== OrganizationStatus::ACTIVE) {
            throw new OrganizationNotActiveException($org->status);
        }

        // ۳) اعتبار مجوز
        if ($org->licenseExpired()) {
            throw new LicenseExpiredException($org->licenseExpiresAt());
        }

        // ۴) سقف هر سفارش
        if ($intent->fineWeightMg > $profile->max_order_mg) {
            throw new LimitExceededException(
                LimitType::PER_ORDER,
                requested: $intent->fineWeightMg,
                limit: $profile->max_order_mg,
            );
        }

        // ۵) سقف روزانه
        $todayVolume = $this->counters->dailyVolume($intent->organizationId);
        if ($todayVolume + $intent->fineWeightMg > $profile->max_daily_volume_mg) {
            throw new LimitExceededException(
                LimitType::DAILY_VOLUME,
                requested: $todayVolume + $intent->fineWeightMg,
                limit: $profile->max_daily_volume_mg,
            );
        }

        // ۶) تعداد سفارش باز
        $openCount = $this->orders->openCountFor($intent->organizationId);
        if ($openCount >= $profile->max_open_orders) {
            throw new LimitExceededException(LimitType::OPEN_ORDERS, ...);
        }

        // ۷) تعهدات باز
        $exposure = $this->calculateExposure($intent->organizationId);
        if ($exposure->goldMg + $intent->fineWeightMg > $profile->max_open_exposure_mg) {
            throw new LimitExceededException(LimitType::OPEN_EXPOSURE, ...);
        }

        // ۸) نوع تسویه مجاز
        if (! in_array($intent->settlementType, $profile->allowed_settlement_types, true)) {
            throw new SettlementTypeNotAllowedException($intent->settlementType);
        }

        // ۹) سقف طرف‌حساب (فقط برای OTC)
        if ($intent->counterpartyOrgId !== null) {
            $this->assertCounterpartyLimit($intent);
        }

        // ۱۰) سقف کاربر
        $this->assertUserLimit($intent);

        // ۱۱) ساعت مجاز معامله
        $this->assertWithinTradingHours($intent);
    }
}
```

<div dir="rtl">

### ترتیب بررسی مهم است

بررسی‌های ارزان (وضعیت، سقف ثابت) اول، بررسی‌های گران (کوئری تجمعی) آخر.

---

## ۱۱.۵ شمارنده‌های روزانه

</div>

```php
// Redis برای سرعت، MySQL برای پایداری

final class DailyCounters
{
    public function increment(int $orgId, FineWeight $volume, Rial $value): void
    {
        $key = "risk:daily:{$orgId}:" . now('Asia/Tehran')->toDateString();

        Redis::pipeline(function ($pipe) use ($key, $volume, $value) {
            $pipe->hincrby($key, 'volume_mg', $volume->milligrams);
            $pipe->hincrby($key, 'value_rial', $value->amount);
            $pipe->hincrby($key, 'trade_count', 1);
            $pipe->expire($key, 172800);   // ۴۸ ساعت
        });

        // نوشتن ناهمگام در MySQL برای گزارش تاریخی
        dispatch(new PersistDailyCounter($orgId, $volume, $value))->onQueue('risk');
    }
}
```

<div dir="rtl">

**بازنشانی:** `php artisan risk:reset-daily-counters` در ۰۰:۰۰ تهران.
اما بهتر است کلید شامل تاریخ باشد تا نیازی به بازنشانی نباشد.

---

## ۱۱.۶ وثیقه (Collateral)

برای اعضایی که می‌خواهند سقف بالاتری داشته باشند:

</div>

```
انواع وثیقه پذیرفته‌شده:
  ├── طلای مسدودشده در خزانه       ضریب پذیرش: ۹۰٪
  ├── سپرده ریالی نزد سامانه        ضریب پذیرش: ۱۰۰٪
  ├── ضمانت‌نامه بانکی              ضریب پذیرش: ۹۵٪
  └── ضمانت شخص ثالث معتبر          ضریب پذیرش: ۵۰٪

سقف اعتباری = اعتبار بدون وثیقه + Σ(ارزش وثیقه × ضریب)

مثال:
  اعتبار بدون وثیقه : 500 گرم
  طلای وثیقه‌شده    : 2,000 گرم × 90% = 1,800 گرم
  ─────────────────────────────────────────────
  سقف کل            : 2,300 گرم
```

<div dir="rtl">

### اجرای وثیقه در نکول

</div>

```
عضو نکول کرد (DEFAULTED)
        │
        ▼
محاسبه مبلغ/وزن تعهد ایفانشده
        │
        ▼
تأیید SETTLEMENT_OFFICER + PLATFORM_ADMIN  (تأیید دوگانه)
        │
        ▼
برداشت از وثیقه به میزان تعهد + جریمه
        │
        ▼
انتقال به طرف زیان‌دیده
        │
        ▼
اگر وثیقه کافی نبود ► باقیمانده به‌عنوان مطالبه ثبت می‌شود
        │
        ▼
تعلیق عضو + اعلان + ارجاع حقوقی
```

<div dir="rtl">

> ⚠️ اجرای وثیقه یک اقدام با پیامد حقوقی جدی است. شرایط، فرآیند و
> اختیارات باید در توافق‌نامه عضویت به‌صراحت مکتوب شده باشد.

---

## ۱۱.۷ ارزیابی سلامت وثیقه (Margin Call)

اگر وثیقه طلا باشد و قیمت طلا نوسان کند:

</div>

```
نسبت پوشش = ارزش وثیقه / تعهدات باز

  > 150٪   ► سالم
  120–150٪ ► هشدار
  110–120٪ ► Margin Call — مهلت ۴ ساعت برای تقویت وثیقه
  < 110٪   ► توقف معامله جدید + اجرای جزئی وثیقه
```

<div dir="rtl">

بررسی در هر به‌روزرسانی قیمت (throttle شده به هر ۵ دقیقه).

---

## ۱۱.۸ درخواست افزایش سقف

</div>

```
عضو درخواست می‌دهد
        │
        ▼
بررسی خودکار پیش‌شرط‌ها:
   ✓ حداقل ۹۰ روز فعالیت
   ✓ حداقل ۵۰ معامله تسویه‌شده
   ✓ نرخ تسویه به‌موقع > ۹۸٪
   ✓ صفر نکول در ۱۸۰ روز
   ✓ KYC کامل و به‌روز
   ✓ credit_score > 700
        │
   ┌────┴────┐
   ▼         ▼
 رد خودکار  ارجاع به بررسی انسانی
 با ذکر         │
 پیش‌شرط        ▼
 نقض‌شده   COMPLIANCE_OFFICER بررسی می‌کند
               │
               ├── تأیید ► سقف جدید + بازبینی در ۹۰ روز
               ├── تأیید مشروط ► نیاز به وثیقه
               └── رد ► با دلیل مکتوب
```

<div dir="rtl">

---

## ۱۱.۹ پنل ریسک ادمین

</div>

```
┌──────────────────────────────────────────────────────────────────┐
│  نمای ریسک سامانه                            ۱۴۰۴/۰۸/۰۵ ۱۴:۳۰   │
├──────────────────────────────────────────────────────────────────┤
│  کل تعهدات باز طلایی    : 12,450 گرم                             │
│  کل تعهدات باز ریالی    : 978 میلیارد ریال                       │
│  تعداد تسویه سررسیدگذشته: ۲  ⚠️                                   │
│  اعضای در وضعیت RESTRICTED: ۳                                    │
├──────────────────────────────────────────────────────────────────┤
│  توزیع سطح ریسک                                                  │
│    LOW      ████████████████░░░░░░  ۶۴ عضو (۴۵٪)                 │
│    MEDIUM   ████████████░░░░░░░░░░  ۵۸ عضو (۴۱٪)                 │
│    HIGH     ███░░░░░░░░░░░░░░░░░░░  ۱۶ عضو (۱۱٪)                 │
│    CRITICAL █░░░░░░░░░░░░░░░░░░░░░   ۴ عضو (۳٪)                  │
├──────────────────────────────────────────────────────────────────┤
│  بزرگ‌ترین تعهدات باز                                             │
│    بنکداری پارس      2,400 گرم   سقف: 5,000   ۴۸٪  ✅            │
│    زرگری اصفهان      1,850 گرم   سقف: 2,000   ۹۳٪  ⚠️            │
│    آبشده یزد           980 گرم   سقف: 1,000   ۹۸٪  🔴            │
├──────────────────────────────────────────────────────────────────┤
│  هشدارهای فعال                                                   │
│    🔴 آبشده یزد — نزدیک سقف تعهدات                                │
│    ⚠️ STL-88301 — ۲ ساعت از مهلت گذشته                            │
│    ⚠️ زرگری تبریز — نسبت پوشش وثیقه ۱۱۸٪                          │
└──────────────────────────────────────────────────────────────────┘
```

<div dir="rtl">

---

## ۱۱.۱۰ آزمون تنش (Stress Test)

سناریوهای دوره‌ای برای سنجش تاب‌آوری:

</div>

```
سناریو ۱ — نکول بزرگ‌ترین عضو
   اگر بزرگ‌ترین بدهکار نکول کند:
   · چند عضو متأثر می‌شوند؟
   · مجموع زیان چقدر است؟
   · آیا وثیقه پوشش می‌دهد؟

سناریو ۲ — سقوط ۲۰٪ قیمت
   · چند وثیقه زیر ۱۱۰٪ می‌روند؟
   · چند Margin Call صادر می‌شود؟

سناریو ۳ — نکول زنجیره‌ای
   اگر A نکول کند و B که به A متکی بود هم نکول کند:
   · عمق زنجیره چقدر است؟

سناریو ۴ — عدم اجرای تهاتر
   اگر یک عضو تهاتر را نپذیرد:
   · چند انتقال ناخالص لازم می‌شود؟
   · آیا نقدینگی اعضا کافی است؟
```

<div dir="rtl">

اجرا: ماهانه، خروجی به‌صورت گزارش برای مدیریت.

</div>
