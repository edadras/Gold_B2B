<div dir="rtl">

# ۱۲. ضدتقلب و مبارزه با پولشویی (AML)

> ⚠️ **هشدار:** الزامات دقیق KYC/AML و گزارش‌دهی در ایران توسط مراجع
> قانونی تعیین می‌شود و در طول زمان تغییر می‌کند. این سند یک **چارچوب فنی**
> ارائه می‌دهد؛ آستانه‌ها، انواع گزارش و مهلت‌های اعلام باید با مشاور
> حقوقی و بر اساس مقررات روز تنظیم شوند.

---

## ۱۲.۱ چرا بازار طلا حساس است

</div>

```
ویژگی‌های طلا که آن را برای پولشویی جذاب می‌کند:
  · ارزش بالا در حجم کم
  · نقدشوندگی جهانی
  · قابلیت ذوب و از بین بردن هویت قطعه
  · سنت معاملات نقدی و غیررسمی
  · دشواری ردیابی مالکیت

نتیجه: سامانه باید از روز اول کنترل داشته باشد، نه اینکه بعداً اضافه کند.
```

<div dir="rtl">

**مزیت طراحی ما:** لایه `Genealogy` و `Ledger` تغییرناپذیر، ردیابی را
به‌مراتب آسان‌تر از بازار سنتی می‌کند. این یک نقطه قوت در مذاکره با
نهادهای ناظر است.

---

## ۱۲.۲ موتور قواعد (Rule Engine)

</div>

```
                  رویداد ورودی
        (معامله، تسویه، سپرده، برداشت)
                       │
                       ▼
        ┌──────────────────────────────┐
        │      Rule Evaluation          │
        │  قواعد فعال، به ترتیب اولویت  │
        └──────────────┬───────────────┘
                       │
          ┌────────────┼────────────┐
          ▼            ▼            ▼
      PASS         WARN         BLOCK
          │            │            │
          │            ▼            ▼
          │       ایجاد Flag    رد عملیات
          │            │       + ایجاد Flag
          │            ▼            │
          │      صف بررسی ◄─────────┘
          ▼
      ادامه عادی
```

<div dir="rtl">

### ساختار قاعده

</div>

```sql
CREATE TABLE aml_rules (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(50) NOT NULL UNIQUE,
  name            VARCHAR(191) NOT NULL,
  description     TEXT NOT NULL,
  category        ENUM('VOLUME','VELOCITY','PATTERN','STRUCTURING',
                       'COUNTERPARTY','BEHAVIORAL','SANCTIONS') NOT NULL,
  severity        ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL,
  action          ENUM('LOG','FLAG','WARN','BLOCK') NOT NULL,
  parameters      JSON NOT NULL,
  applies_to      JSON NOT NULL,       -- انواع رویداد
  is_active       BOOLEAN NOT NULL DEFAULT TRUE,
  created_at, updated_at
) ENGINE=InnoDB;
```

<div dir="rtl">

---

## ۱۲.۳ کاتالوگ قواعد پیشنهادی

### دسته A — حجم

| کد | قاعده | آستانه نمونه | شدت | اقدام |
|---|---|---|---|---|
| `VOL-01` | معامله منفرد بزرگ | > ۱۰ کیلوگرم | MEDIUM | FLAG |
| `VOL-02` | حجم روزانه غیرعادی | > ۵× میانگین ۳۰ روزه | HIGH | FLAG |
| `VOL-03` | جهش ناگهانی از عضو کم‌فعالیت | ۱۰× افزایش | HIGH | WARN |
| `VOL-04` | حجم بیش از ظرفیت اعلامی کسب‌وکار | نسبت به اندازه واحد صنفی | MEDIUM | FLAG |

### دسته B — سرعت (Velocity)

| کد | قاعده | آستانه نمونه | شدت |
|---|---|---|---|
| `VEL-01` | تعداد معامله در ساعت | > ۵۰ | MEDIUM |
| `VEL-02` | معامله در ساعات غیرمعمول | خارج ۰۸–۲۰ | LOW |
| `VEL-03` | معاملات پی‌درپی با یک طرف | > ۲۰ در روز | MEDIUM |

### دسته C — الگو

| کد | قاعده | توضیح | شدت |
|---|---|---|---|
| `PAT-01` | معامله رفت‌وبرگشتی | A→B سپس B→A در بازه کوتاه با حجم مشابه | HIGH |
| `PAT-02` | معامله دایره‌ای | A→B→C→A | CRITICAL |
| `PAT-03` | خرید و فروش فوری بدون سود | خرید و فروش همان مقدار در < ۱۰ دقیقه | HIGH |
| `PAT-04` | قیمت به‌طور مکرر خارج از بازار | > ۵٪ انحراف در بیش از ۳ معامله | HIGH |
| `PAT-05` | ذوب فوری پس از خرید | Melt در < ۲۴ ساعت از خرید | MEDIUM |

### دسته D — تقسیم‌بندی (Structuring)

| کد | قاعده | توضیح | شدت |
|---|---|---|---|
| `STR-01` | چند معامله کوچک زیر آستانه | ≥ ۵ معامله در روز، هرکدام ۹۰–۹۹٪ آستانه | HIGH |
| `STR-02` | مجموع روزانه با طرف واحد بالای آستانه | با وجود معاملات منفرد کوچک | HIGH |
| `STR-03` | برداشت‌های متعدد کوچک | الگوی مشابه | MEDIUM |

### دسته E — طرف‌حساب

| کد | قاعده | شدت |
|---|---|---|
| `CPT-01` | معامله با عضو دارای پرچم فعال | HIGH |
| `CPT-02` | تمرکز بیش از ۸۰٪ حجم روی یک طرف | MEDIUM |
| `CPT-03` | طرف‌حساب جدید با حجم بسیار بالا در اولین معامله | HIGH |
| `CPT-04` | تلاش برای Self-Trade | CRITICAL |

### دسته F — رفتاری

| کد | قاعده | شدت |
|---|---|---|
| `BEH-01` | ورود از موقعیت جغرافیایی غیرمعمول | MEDIUM |
| `BEH-02` | تغییر ناگهانی در الگوی ساعات فعالیت | LOW |
| `BEH-03` | تغییر حساب بانکی و سپس برداشت بزرگ | HIGH |
| `BEH-04` | چند تلاش ناموفق ورود سپس معامله بزرگ | HIGH |

### دسته G — تحریم و فهرست‌ها

| کد | قاعده | شدت | اقدام |
|---|---|---|---|
| `SAN-01` | تطابق نام با فهرست محدودشده | CRITICAL | BLOCK |
| `SAN-02` | تطابق جزئی (fuzzy) با فهرست | HIGH | FLAG |

---

## ۱۲.۴ پیاده‌سازی نمونه قاعده

</div>

```php
namespace App\Modules\Risk\Aml\Rules;

/** PAT-01 — تشخیص معامله رفت‌وبرگشتی */
final class RoundTripTradeRule implements AmlRule
{
    public function code(): string { return 'PAT-01'; }

    public function evaluate(AmlContext $ctx): RuleResult
    {
        if (! $ctx->event instanceof TradeExecuted) {
            return RuleResult::notApplicable();
        }

        $params = $this->parameters();   // از دیتابیس
        $window = $params['window_minutes'] ?? 60;
        $tolerance = $params['weight_tolerance_bps'] ?? 500;   // ۵٪

        // آیا در بازه اخیر معامله معکوس با همین طرف وجود دارد؟
        $reverse = Trade::query()
            ->where('buyer_organization_id', $ctx->event->sellerOrganizationId)
            ->where('seller_organization_id', $ctx->event->buyerOrganizationId)
            ->where('executed_at', '>=', now()->subMinutes($window))
            ->get()
            ->first(fn ($t) => $this->weightsAreSimilar(
                $t->quantity_fine_mg,
                $ctx->event->fineWeightMg,
                $tolerance,
            ));

        if ($reverse === null) {
            return RuleResult::pass();
        }

        return RuleResult::flag(
            severity: Severity::HIGH,
            summary: 'معامله رفت‌وبرگشتی شناسایی شد',
            context: [
                'current_trade'  => $ctx->event->tradeId,
                'reverse_trade'  => $reverse->id,
                'minutes_apart'  => $reverse->executed_at->diffInMinutes(now()),
                'weight_current' => $ctx->event->fineWeightMg,
                'weight_reverse' => $reverse->quantity_fine_mg,
                'price_current'  => $ctx->event->pricePerGramRial,
                'price_reverse'  => $reverse->price_per_gram_rial,
            ],
        );
    }

    private function weightsAreSimilar(int $a, int $b, int $toleranceBps): bool
    {
        if ($a === 0 || $b === 0) return false;
        $diff = abs($a - $b);
        return ($diff * 10000 / max($a, $b)) <= $toleranceBps;
    }
}
```

<div dir="rtl">

---

## ۱۲.۵ ماشین حالت پرچم (Flag)

</div>

```
   ┌────────┐
   │  OPEN  │  پرچم ایجاد شد
   └───┬────┘
       │ اختصاص به بررسی‌کننده
       ▼
   ┌──────────────┐
   │ UNDER_REVIEW │
   └──────┬───────┘
          │
   ┌──────┼──────┬──────────────┐
   ▼      ▼      ▼              ▼
┌───────┐┌─────────┐┌──────────────────┐┌───────────┐
│CLEARED││ESCALATED││ ACTION_REQUIRED  ││FALSE_POS. │
└───────┘└────┬────┘└────────┬─────────┘└───────────┘
 بی‌مورد      │              │            قاعده باید
              ▼              ▼            تنظیم شود
      ┌────────────────┐  ┌──────────────┐
      │ENHANCED_REVIEW │  │ محدودسازی/   │
      └───────┬────────┘  │ تعلیق عضو    │
              │           └──────────────┘
       ┌──────┼──────┐
       ▼      ▼      ▼
   CLEARED  گزارش  تعلیق
            قانونی
```

<div dir="rtl">

</div>

```sql
CREATE TABLE aml_flags (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_code           VARCHAR(50) NOT NULL,
  organization_id     BIGINT UNSIGNED NOT NULL,
  severity            ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL,
  status              ENUM('OPEN','UNDER_REVIEW','ENHANCED_REVIEW',
                           'CLEARED','FALSE_POSITIVE','ESCALATED',
                           'ACTION_TAKEN') NOT NULL,
  summary             VARCHAR(500) NOT NULL,
  context             JSON NOT NULL,
  subject_type        VARCHAR(50) NULL,      -- trade, settlement, ...
  subject_id          BIGINT UNSIGNED NULL,
  raised_at           TIMESTAMP NOT NULL,
  assigned_to_user_id BIGINT UNSIGNED NULL,
  reviewed_at         TIMESTAMP NULL,
  reviewed_by_user_id BIGINT UNSIGNED NULL,
  resolution_notes    TEXT NULL,
  action_taken        VARCHAR(200) NULL,
  reported_at         TIMESTAMP NULL,        -- اگر گزارش قانونی شده
  report_reference    VARCHAR(100) NULL,
  KEY idx_org_status (organization_id, status),
  KEY idx_severity_status (severity, status),
  KEY idx_raised (raised_at)
) ENGINE=InnoDB;
```

<div dir="rtl">

### SLA بررسی

| شدت | مهلت اختصاص | مهلت تصمیم |
|---|---|---|
| `CRITICAL` | فوری (خودکار BLOCK) | ۲ ساعت |
| `HIGH` | ۱ ساعت | ۲۴ ساعت |
| `MEDIUM` | ۴ ساعت | ۷۲ ساعت |
| `LOW` | ۲۴ ساعت | ۷ روز |

---

## ۱۲.۶ مسیر تشدید عضو

</div>

```
NORMAL
   │  پرچم MEDIUM یا بالاتر
   ▼
MONITORED
   │  · هیچ محدودیتی اعمال نمی‌شود
   │  · اما همه فعالیت‌ها با جزئیات بیشتر ثبت می‌شود
   │  · بازبینی هفتگی
   │
   │  پرچم HIGH یا ۳ پرچم MEDIUM
   ▼
ENHANCED_DUE_DILIGENCE
   │  · درخواست مدارک تکمیلی (منشأ وجوه، قراردادها)
   │  · کاهش سقف‌ها
   │  · تأیید دستی معاملات بزرگ
   │
   │  عدم پاسخ یا پرچم CRITICAL
   ▼
RESTRICTED
   │  · فقط تسویه تعهدات موجود
   │  · بدون معامله جدید
   │
   │  تصمیم Compliance
   ▼
SUSPENDED
   │  · بدون هیچ فعالیتی
   │  · دارایی قفل تا تعیین تکلیف
   │
   ▼
گزارش به مراجع قانونی (طبق الزام)
```

<div dir="rtl">

---

## ۱۲.۷ صف کار افسر انطباق

</div>

```
┌────────────────────────────────────────────────────────────────┐
│  صف پرچم‌های AML                                    ۱۴ مورد    │
├────────────────────────────────────────────────────────────────┤
│  🔴 CRITICAL (۱)                                                │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ PAT-02 معامله دایره‌ای                       ۱۲ دقیقه پیش │  │
│  │ ORG-291 → ORG-445 → ORG-112 → ORG-291                    │  │
│  │ حجم: 2,400 گرم | بازه: ۴۷ دقیقه                          │  │
│  │ ⛔ معاملات این چرخه به‌صورت خودکار مسدود شدند              │  │
│  │ [بررسی]  [مشاهده گراف]                                    │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                │
│  🟠 HIGH (۴)                                                    │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ STR-01 تقسیم‌بندی معاملات                     ۲ ساعت پیش  │  │
│  │ ORG-338 — ۷ معامله، هرکدام 950–990 گرم (آستانه: 1000)    │  │
│  │ [بررسی]                                                   │  │
│  ├──────────────────────────────────────────────────────────┤  │
│  │ BEH-03 تغییر حساب و برداشت بزرگ              ۴ ساعت پیش  │  │
│  │ ORG-102 — حساب جدید ۲ ساعت پیش، برداشت 5 کیلو            │  │
│  │ [بررسی]                                                   │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                │
│  🟡 MEDIUM (۶)     🔵 LOW (۳)                                   │
└────────────────────────────────────────────────────────────────┘
```

<div dir="rtl">

### ابزار بررسی

</div>

```
صفحه بررسی پرچم باید این‌ها را در دسترس بگذارد:

  ├── تاریخچه کامل معاملات عضو (۹۰ روز)
  ├── گراف روابط طرف‌حساب (بصری)
  ├── نمودار حجم و الگوی زمانی
  ├── تمام پرچم‌های قبلی همان عضو
  ├── اسناد KYC
  ├── شجره‌نامه lotهای درگیر
  ├── مقایسه با میانگین اعضای مشابه
  └── ابزار یادداشت و پیوست مدرک

هر اقدام باید یادداشت اجباری داشته باشد.
```

<div dir="rtl">

---

## ۱۲.۸ گراف روابط

ابزار بصری برای تشخیص الگوهای دایره‌ای:

</div>

```
                    ORG-291
                   ╱   ▲   ╲
            2,400g╱    │    ╲
                 ╱  2,400g   ╲
                ▼      │      ╲
           ORG-445     │    ORG-112
                ╲      │      ▲
            2,400g╲    │     ╱
                   ╲   │    ╱2,400g
                    ▼  │   ╱
                    ORG-112

  ⚠️ چرخه شناسایی شد: 291 → 445 → 112 → 291
     حجم یکسان، بازه ۴۷ دقیقه، سود خالص تقریباً صفر
```

<div dir="rtl">

### الگوریتم تشخیص چرخه

</div>

```php
/**
 * تشخیص چرخه در گراف معاملات با DFS محدود.
 * فقط معاملات بازه اخیر بررسی می‌شود.
 */
public function detectCycles(int $windowMinutes = 120, int $maxDepth = 5): array
{
    $edges = Trade::where('executed_at', '>=', now()->subMinutes($windowMinutes))
        ->get(['seller_organization_id', 'buyer_organization_id',
               'quantity_fine_mg', 'id', 'executed_at']);

    $graph = [];
    foreach ($edges as $e) {
        $graph[$e->seller_organization_id][] = $e;
    }

    $cycles = [];
    foreach (array_keys($graph) as $start) {
        $this->dfs($graph, $start, $start, [], $cycles, $maxDepth);
    }

    // فیلتر: فقط چرخه‌هایی با حجم مشابه در همه یال‌ها
    return array_filter($cycles, fn ($c) => $this->weightsAreSimilar($c));
}
```

<div dir="rtl">

---

## ۱۲.۹ کاهش مثبت کاذب

</div>

```
مسئله: قواعد سختگیرانه ► پرچم زیاد ► خستگی بررسی‌کننده ► نادیده گرفتن

راهکارها:

۱) خط پایه هر عضو (Baseline)
   آستانه‌ها نسبی باشند، نه مطلق:
   «۵ برابر میانگین خودش» بهتر از «بالای ۱۰ کیلو» است

۲) دوره یادگیری
   عضو جدید ۳۰ روز فقط رصد می‌شود؛ خط پایه ساخته می‌شود

۳) بازخورد
   هر پرچم FALSE_POSITIVE ► بازبینی پارامتر قاعده
   نرخ مثبت کاذب > ۷۰٪ ► غیرفعال‌سازی موقت قاعده و بازطراحی

۴) ترکیب قواعد
   یک پرچم MEDIUM ► رصد
   سه پرچم MEDIUM همزمان ► HIGH

۵) لیست سفید موجه
   الگوهایی که برای یک عضو خاص طبیعی است، پس از بررسی
   می‌تواند استثنا شود (با ثبت دلیل و بازبینی دوره‌ای)
```

<div dir="rtl">

---

## ۱۲.۱۰ گزارش‌دهی قانونی

</div>

```
⚠️ نوع گزارش، آستانه، فرمت و مهلت اعلام باید بر اساس
   مقررات جاری و با تأیید مشاور حقوقی تعیین شود.

آنچه سیستم باید فراهم کند:

  ├── قابلیت تولید گزارش در فرمت موردنیاز
  ├── ثبت زمان و مرجع هر گزارش ارسالی
  ├── عدم اطلاع‌رسانی به عضو درباره گزارش (Tipping-off)
  ├── نگهداری کامل سوابق برای دوره الزامی
  ├── قابلیت پاسخ به استعلام مراجع
  └── دسترسی حسابرس مستقل
```

<div dir="rtl">

### قاعده Tipping-off

</div>

```
❌ عضو نباید بفهمد که درباره او گزارش داده شده

پیامد فنی:
  · پرچم‌های AML هرگز در پنل عضو نمایش داده نمی‌شوند
  · دلیل محدودسازی به‌صورت کلی اعلام می‌شود
    («بررسی انطباق در جریان است»)، نه با ذکر قاعده
  · سطح دسترسی جداگانه برای داده AML
  · حتی PLATFORM_ADMIN عادی نباید همه را ببیند
```

<div dir="rtl">

---

## ۱۲.۱۱ نگهداری سوابق

| داده | مدت نگهداری |
|---|---|
| سوابق KYC | ۱۰ سال پس از پایان رابطه |
| سوابق معاملات | ۱۰ سال |
| پرچم‌ها و بررسی‌ها | ۱۰ سال |
| گزارش‌های ارسالی | دائمی |
| Audit Log مربوط به AML | ۱۰ سال |

> مدت‌های بالا نمونه هستند و باید با الزام قانونی تطبیق داده شوند.

</div>
