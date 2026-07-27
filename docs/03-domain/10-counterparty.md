<div dir="rtl">

# ۱۰. مدیریت طرف‌حساب

## ۱۰.۱ مسئله

در بازار طلا، رابطه بین دو عضو یک **رابطه دوواحدی مستمر** است:

</div>

```
طلافروشی کریمی  ↔  بنکداری پارس

  کریمی از پارس طلبکار است    : 250 گرم
  کریمی به پارس بدهکار است    : 1,200,000,000 ریال

  → این یک «رابطه» است، نه چند معامله جدا
```

<div dir="rtl">

سیستم باید این رابطه را به‌صورت زنده و دوواحدی نمایش دهد.

---

## ۱۰.۲ مدل داده

</div>

```sql
CREATE TABLE counterparty_relations (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id       BIGINT UNSIGNED NOT NULL,
  counterparty_org_id   BIGINT UNSIGNED NOT NULL,

  -- مانده طلایی: مثبت = طرف مقابل به ما بدهکار است
  gold_balance_mg       BIGINT NOT NULL DEFAULT 0,
  -- مانده ریالی: مثبت = طرف مقابل به ما بدهکار است
  rial_balance          BIGINT NOT NULL DEFAULT 0,

  -- سقف اعتبار اعطایی توسط ما به آن‌ها
  gold_credit_limit_mg  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rial_credit_limit     BIGINT UNSIGNED NOT NULL DEFAULT 0,

  -- آمار رابطه
  first_trade_at        TIMESTAMP NULL,
  last_trade_at         TIMESTAMP NULL,
  total_trade_count     INT UNSIGNED NOT NULL DEFAULT 0,
  total_volume_mg       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  overdue_count         INT UNSIGNED NOT NULL DEFAULT 0,
  dispute_count         INT UNSIGNED NOT NULL DEFAULT 0,

  -- تنظیمات
  is_trusted            BOOLEAN NOT NULL DEFAULT FALSE,
  is_blocked            BOOLEAN NOT NULL DEFAULT FALSE,
  auto_accept_otc       BOOLEAN NOT NULL DEFAULT FALSE,
  internal_note         TEXT NULL,

  updated_at            TIMESTAMP NOT NULL,
  UNIQUE KEY uq_pair (organization_id, counterparty_org_id),
  KEY idx_counterparty (counterparty_org_id)
) ENGINE=InnoDB;
```

<div dir="rtl">

### ثابت تقارن

</div>

```
برای هر جفت (A, B):
    relation(A,B).gold_balance_mg = −relation(B,A).gold_balance_mg
    relation(A,B).rial_balance    = −relation(B,A).rial_balance

این ثابت در reconcile روزانه بررسی می‌شود.
```

<div dir="rtl">

---

## ۱۰.۳ نمایش صورت‌حساب طرف مقابل

</div>

```
┌─────────────────────────────────────────────────────────────────────┐
│  صورت‌حساب — بنکداری پارس (ORG-291)          از ۱۴۰۴/۰۸/۰۱          │
├─────────────────────────────────────────────────────────────────────┤
│  تاریخ    شرح                  طلا (گرم)      ریال          مانده    │
│                                بد / بس        بد / بس                │
├─────────────────────────────────────────────────────────────────────┤
│  08/01  مانده ابتدای دوره          —            —      0 / 0        │
│  08/03  خرید TRD-88201        +250.000  −19,522,500,000              │
│                                              250 g / −19.52B         │
│  08/05  تسویه ریالی                 —   +19,522,500,000              │
│                                              250 g / 0               │
│  08/07  فروش TRD-88290        −100.000   +7,848,000,000              │
│                                              150 g / +7.85B          │
│  08/09  تسویه طلایی           −150.000            —                  │
│                                                0 g / +7.85B          │
│  08/12  تسویه ریالی                 —    −7,848,000,000              │
│                                                0 g / 0               │
├─────────────────────────────────────────────────────────────────────┤
│  مانده پایانی                    0.000 گرم         0 ریال            │
│                                                                     │
│  آمار رابطه:                                                         │
│    تعداد معاملات    : ۴۲                                             │
│    حجم کل           : 8,420 گرم                                     │
│    اولین معامله     : ۱۴۰۳/۱۱/۲۰                                    │
│    تأخیر در تسویه   : ۰ مورد                                        │
│    اختلاف           : ۰ مورد                                        │
│    ارزیابی          : ⭐⭐⭐⭐⭐ قابل اعتماد                          │
├─────────────────────────────────────────────────────────────────────┤
│  سقف اعتبار اعطایی من به ایشان:                                      │
│    طلا  : 500 گرم        استفاده‌شده: 0 گرم                          │
│    ریال : 5,000,000,000  استفاده‌شده: 0                              │
│    [ویرایش سقف]                                                     │
├─────────────────────────────────────────────────────────────────────┤
│  [تأیید مانده]  [درخواست تسویه]  [پیشنهاد تهاتر]  [Excel]           │
└─────────────────────────────────────────────────────────────────────┘
```

<div dir="rtl">

---

## ۱۰.۴ تأیید متقابل مانده (Balance Confirmation)

مکانیزمی برای رفع مغایرت پیش از تبدیل شدن به اختلاف:

</div>

```
۱) عضو A درخواست تأیید مانده می‌فرستد
   «تا تاریخ ۱۴۰۴/۰۸/۳۰ مانده شما نزد من: بدهکار 250 گرم»
              │
              ▼
۲) عضو B دریافت می‌کند و مقایسه می‌کند
              │
       ┌──────┴──────┐
       ▼             ▼
   تأیید          مغایرت
       │             │
       ▼             ▼
  ثبت تأیید    B مقدار خودش را اعلام می‌کند
  دو طرف            │
                    ▼
              سیستم تفاوت را محاسبه و
              معاملات مربوطه را نمایش می‌دهد
                    │
                    ▼
              ┌─────┴─────┐
              ▼           ▼
        حل توافقی    ارجاع به Dispute
```

<div dir="rtl">

**فایده:** بیشتر مغایرت‌ها ناشی از خطای ثبت یا اختلاف در زمان‌بندی
شناسایی معامله است و با یک بررسی مشترک حل می‌شود.

---

## ۱۰.۵ سقف اعتبار دوطرفه

هر عضو می‌تواند برای هر طرف‌حساب سقف تعریف کند:

</div>

```
سقف اعتبار من به بنکداری پارس:
  ├── طلا  : 500 گرم       ► تا این مقدار می‌توانم طلا بدهم و بعد پول بگیرم
  └── ریال : 5 میلیارد     ► تا این مقدار می‌توانم پول بدهم و بعد طلا بگیرم

هنگام ثبت سفارش OTC با پارس:
  ✓ بررسی می‌شود که مانده پس از معامله از سقف نگذرد
  ✗ اگر بگذرد ► هشدار + نیاز به تأیید صریح یا رد
```

<div dir="rtl">

### سقف مؤثر

</div>

```
سقف مؤثر برای معامله بین A و B =
    min(
        سقف اعطایی A به B,
        سقف کلی B از سامانه (Risk Module),
        باقیمانده سقف روزانه B,
    )
```

<div dir="rtl">

---

## ۱۰.۶ تمرکز ریسک

هشدار وقتی بخش بزرگی از دارایی نزد یک طرف‌حساب است:

</div>

```
┌─────────────────────────────────────────────────────────┐
│  ⚠️ هشدار تمرکز ریسک                                     │
│                                                         │
│  ۶۸٪ از مطالبات شما نزد یک طرف‌حساب است:                 │
│                                                         │
│    بنکداری پارس     1,200 گرم    ۶۸٪  ████████████░░    │
│    زرگری اصفهان       350 گرم    ۲۰٪  ████░░░░░░░░░░    │
│    آبشده یزد          210 گرم    ۱۲٪  ██░░░░░░░░░░░░    │
│                                                         │
│  در صورت نکول این طرف‌حساب، ۶۸٪ مطالبات در خطر است.      │
│  [مشاهده جزئیات]  [تنظیم آستانه هشدار]                   │
└─────────────────────────────────────────────────────────┘
```

<div dir="rtl">

آستانه پیش‌فرض: هشدار در ۴۰٪، هشدار جدی در ۶۰٪.

---

## ۱۰.۷ لیست سفید و سیاه

</div>

```
is_trusted = true
  ► نمایش در بالای لیست RFQ
  ► امکان auto_accept برای پیشنهادهای زیر سقف مشخص
  ► اعلان اولویت‌دار برای پیشنهادهای این طرف

is_blocked = true
  ► پیشنهاد OTC از این طرف دریافت نمی‌شود
  ► در RFQ به این طرف ارسال نمی‌شود
  ⚠️ اما در Order Book همچنان ممکن است تطبیق شوند
     (چون Order Book ناشناس است)
```

<div dir="rtl">

### گزینه: منع تطبیق در Order Book

</div>

```
برخی اعضا می‌خواهند اصلاً با یک طرف خاص معامله نکنند.

راهکار: فیلتر در Matching Engine
   AND NOT EXISTS (
       SELECT 1 FROM counterparty_relations
       WHERE organization_id = maker.organization_id
         AND counterparty_org_id = taker.organization_id
         AND is_blocked = TRUE
   )

⚠️ هزینه: کاهش کارایی تطبیق و پیچیدگی کوئری
⚠️ ریسک: نشت اطلاعات (اگر همیشه رد شود، طرف مقابل می‌فهمد بلاک شده)

تصمیم: پیاده‌سازی می‌شود، اما بدون هیچ بازخوردی به طرف بلاک‌شده.
        سفارش او صرفاً با دیگری تطبیق می‌یابد.
```

<div dir="rtl">

---

## ۱۰.۸ پیشنهاد تسویه بین دو طرف

</div>

```
مانده فعلی با بنکداری پارس:
   طلا : من طلبکار 250 گرم
   ریال: من بدهکار 19,522,500,000

پیشنهادهای سیستم:

  گزینه ۱ — تسویه جداگانه
     پارس 250 گرم به من می‌دهد
     من 19,522,500,000 ریال به پارس می‌دهم
     ► ۲ عملیات

  گزینه ۲ — تسویه متقاطع
     با قیمت روز 78,480,000 ریال/گرم:
     250 گرم = 19,620,000,000 ریال
     پس از تهاتر: پارس 97,500,000 ریال به من بدهکار می‌ماند
     ► ۱ عملیات کوچک
     ⚠️ نیازمند توافق صریح بر نرخ تبدیل

  [انتخاب گزینه]  [پیشنهاد به طرف مقابل]
```

<div dir="rtl">

---

## ۱۰.۹ به‌روزرسانی خودکار رابطه

</div>

```php
// Listener روی TradeExecuted و SettlementCompleted

public function handle(SettlementCompleted $event): void
{
    DB::transaction(function () use ($event) {
        $this->updateRelation(
            $event->goldDelivererOrgId,
            $event->goldReceiverOrgId,
            goldDelta: -$event->fineWeightMg,
            rialDelta: +$event->cashAmountRial,
        );

        $this->updateRelation(
            $event->goldReceiverOrgId,
            $event->goldDelivererOrgId,
            goldDelta: +$event->fineWeightMg,
            rialDelta: -$event->cashAmountRial,
        );
    });
}

private function updateRelation(int $orgId, int $cpId, int $goldDelta, int $rialDelta): void
{
    // upsert اتمیک — از race condition جلوگیری می‌کند
    DB::statement("
        INSERT INTO counterparty_relations
            (organization_id, counterparty_org_id, gold_balance_mg,
             rial_balance, total_trade_count, last_trade_at, updated_at)
        VALUES (?, ?, ?, ?, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            gold_balance_mg   = gold_balance_mg + VALUES(gold_balance_mg),
            rial_balance      = rial_balance + VALUES(rial_balance),
            total_trade_count = total_trade_count + 1,
            last_trade_at     = NOW(),
            updated_at        = NOW()
    ", [$orgId, $cpId, $goldDelta, $rialDelta]);
}
```

<div dir="rtl">

---

## ۱۰.۱۰ مغایرت‌گیری روابط

</div>

```php
// php artisan counterparty:reconcile

// ۱) بررسی تقارن
$asymmetric = DB::select("
    SELECT a.organization_id, a.counterparty_org_id,
           a.gold_balance_mg AS a_gold, b.gold_balance_mg AS b_gold,
           a.rial_balance AS a_rial, b.rial_balance AS b_rial
    FROM counterparty_relations a
    JOIN counterparty_relations b
      ON b.organization_id = a.counterparty_org_id
     AND b.counterparty_org_id = a.organization_id
    WHERE a.gold_balance_mg != -b.gold_balance_mg
       OR a.rial_balance != -b.rial_balance
");

foreach ($asymmetric as $row) {
    Alert::critical("Counterparty relation asymmetry", (array) $row);
}

// ۲) بازسازی از روی معاملات
foreach (CounterpartyRelation::cursor() as $rel) {
    $computed = $this->computeFromSettlements($rel);

    if ($computed->goldMg !== $rel->gold_balance_mg
        || $computed->rial !== $rel->rial_balance) {
        Alert::warning("Relation drift detected", [...]);
        $rel->update([
            'gold_balance_mg' => $computed->goldMg,
            'rial_balance'    => $computed->rial,
        ]);
    }
}
```

</div>
