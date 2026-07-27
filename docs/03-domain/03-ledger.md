<div dir="rtl">

# ۳. دفتر کل (Ledger) — قلب سیستم

> این سند مهم‌ترین بخش مستندات است. هر تصمیمی در سایر ماژول‌ها باید با
> قواعد این سند سازگار باشد.

---

## ۳.۱ اصل بنیادین

</div>

```
❌ هرگز:
    UPDATE organizations SET gold_balance = gold_balance - 100 WHERE id = 5;

✅ همیشه:
    INSERT INTO ledger_entries (account_id, amount, type, reference, ...)
    VALUES (5, -100000, 'TRADE_DEBIT', 'TRD-88231', ...);
```

<div dir="rtl">

**دلیل:** `UPDATE` تاریخچه را نابود می‌کند. با `INSERT` تاریخچه کامل
باقی می‌ماند و مانده همیشه قابل بازسازی است.

---

## ۳.۲ ساختار حساب‌ها

هر سازمان مجموعه‌ای از حساب‌های دفتر دارد:

</div>

```
Organization #184
│
├── GOLD Ledger
│   ├── AVAILABLE     مانده قابل استفاده          95,565,000 mg
│   ├── RESERVED      قفل‌شده بابت سفارش باز       12,000,000 mg
│   ├── IN_SETTLEMENT در جریان تسویه               5,000,000 mg
│   ├── IN_DISPUTE    قفل‌شده بابت اختلاف                  0 mg
│   └── ─────────────────────────────────────────────────────
│       TOTAL                                    112,565,000 mg
│
└── RIAL Ledger
    ├── AVAILABLE                              4,200,000,000 ریال
    ├── RESERVED                               1,500,000,000 ریال
    ├── IN_SETTLEMENT                            800,000,000 ریال
    ├── IN_DISPUTE                                        0 ریال
    ├── PAYABLE       بدهی (منفی مجاز)          −300,000,000 ریال
    └── ────────────────────────────────────────────────────
        NET                                    6,200,000,000 ریال
```

<div dir="rtl">

### مدل داده

</div>

```sql
CREATE TABLE ledger_accounts (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  asset_type      ENUM('GOLD','RIAL') NOT NULL,
  metal_type      ENUM('GOLD','SILVER','PLATINUM') NULL,  -- فقط برای asset_type=GOLD
  bucket          ENUM('AVAILABLE','RESERVED','IN_SETTLEMENT',
                       'IN_DISPUTE','PAYABLE') NOT NULL,
  currency        CHAR(3) NULL DEFAULT 'IRR',
  status          ENUM('ACTIVE','FROZEN','CLOSED') NOT NULL DEFAULT 'ACTIVE',
  created_at      TIMESTAMP NOT NULL,
  UNIQUE KEY uq_org_asset_bucket (organization_id, asset_type, metal_type, bucket),
  KEY idx_org (organization_id)
) ENGINE=InnoDB;
```

<div dir="rtl">

</div>

```sql
CREATE TABLE ledger_entries (
  id                BIGINT UNSIGNED AUTO_INCREMENT,
  account_id        BIGINT UNSIGNED NOT NULL,
  organization_id   BIGINT UNSIGNED NOT NULL,     -- غیرنرمال برای سرعت
  asset_type        ENUM('GOLD','RIAL') NOT NULL,

  -- مقدار: مثبت = بستانکار، منفی = بدهکار
  -- واحد: میلی‌گرم برای GOLD، ریال برای RIAL
  amount            BIGINT NOT NULL,

  entry_type        VARCHAR(50) NOT NULL,         -- فهرست کامل در ۳.۴
  direction         ENUM('CREDIT','DEBIT') NOT NULL,

  -- مرجع: چه چیزی باعث این ثبت شد
  reference_type    VARCHAR(50) NOT NULL,         -- 'trade','settlement','adjustment',...
  reference_id      BIGINT UNSIGNED NOT NULL,

  -- گروه‌بندی ثبت‌های مرتبط (یک معامله = چند entry با یک group_id)
  transaction_group CHAR(36) NOT NULL,

  -- برای Reversal
  reverses_entry_id BIGINT UNSIGNED NULL,
  reversed_by_entry_id BIGINT UNSIGNED NULL,

  -- مانده پس از این ثبت (برای بررسی سریع، قابل بازسازی)
  balance_after     BIGINT NOT NULL,

  description       VARCHAR(500) NULL,
  metadata          JSON NULL,

  created_by_user_id BIGINT UNSIGNED NULL,
  created_at        TIMESTAMP(6) NOT NULL,

  -- زنجیره یکپارچگی
  prev_hash         CHAR(64) NULL,
  row_hash          CHAR(64) NOT NULL,

  PRIMARY KEY (id, created_at),                   -- برای پارتیشن‌بندی
  KEY idx_account_time (account_id, created_at),
  KEY idx_org_time (organization_id, created_at),
  KEY idx_reference (reference_type, reference_id),
  KEY idx_group (transaction_group),
  KEY idx_reverses (reverses_entry_id)
) ENGINE=InnoDB
PARTITION BY RANGE (UNIX_TIMESTAMP(created_at)) (
  PARTITION p2026_01 VALUES LESS THAN (UNIX_TIMESTAMP('2026-02-01')),
  PARTITION p2026_02 VALUES LESS THAN (UNIX_TIMESTAMP('2026-03-01')),
  -- ...
  PARTITION pmax VALUES LESS THAN MAXVALUE
);
```

<div dir="rtl">

</div>

```sql
-- کش مانده — قابل بازسازی، منبع حقیقت نیست
CREATE TABLE ledger_balances (
  account_id        BIGINT UNSIGNED PRIMARY KEY,
  balance           BIGINT NOT NULL DEFAULT 0,
  last_entry_id     BIGINT UNSIGNED NULL,
  entry_count       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at        TIMESTAMP(6) NOT NULL,
  version           BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- optimistic lock
  CONSTRAINT chk_available_non_negative CHECK (balance >= 0)
) ENGINE=InnoDB;
```

<div dir="rtl">

> ⚠️ `CHECK` روی `balance >= 0` فقط برای bucketهای `AVAILABLE`, `RESERVED`,
> `IN_SETTLEMENT`, `IN_DISPUTE` معنا دارد. `PAYABLE` می‌تواند منفی باشد.
> راهکار در MySQL: جدول جداگانه یا اعمال در اپلیکیشن + trigger.

---

## ۳.۳ حساب‌های سیستمی

علاوه بر حساب اعضا، سیستم حساب‌های داخلی دارد تا **بقای جرم** برقرار بماند:

</div>

```
SYSTEM ACCOUNTS (organization_id = 0)
│
├── FEE_INCOME           درآمد کارمزد
├── ROUNDING_DIFFERENCE  تفاوت گِردکردن
├── PROCESSING_LOSS      افت ذوب و برش
├── ASSAY_VARIANCE       اختلاف ری‌گیری
├── EXTERNAL_GOLD_IN     ورود طلا از خارج سیستم
├── EXTERNAL_GOLD_OUT    خروج طلا از سیستم
├── EXTERNAL_CASH_IN     ورود وجه
├── EXTERNAL_CASH_OUT    خروج وجه
├── SUSPENSE             حساب معلق (مغایرت موقت)
└── CLEARING             حساب پایاپای (برای تهاتر چندطرفه)
```

<div dir="rtl">

### قاعده طلایی — بقای جرم

</div>

```
برای هر transaction_group:

    Σ amount (تمام entryهای گروه، همه حساب‌ها، همان asset_type) = 0

این ثابت در هر ثبت بررسی می‌شود و در reconcile روزانه دوباره.
```

<div dir="rtl">

**مثال — معامله ۱۰۰ گرم خالص:**

</div>

```
transaction_group: 7f3a-...

Account                                    amount (mg)
────────────────────────────────────────────────────────
Org-184 / GOLD / AVAILABLE                  -100,000
Org-291 / GOLD / AVAILABLE                  +100,000
                                            ─────────
                                    مجموع:        0  ✅
```

<div dir="rtl">

**مثال — معامله با کارمزد ریالی:**

</div>

```
transaction_group: 8b2c-...

Account                                    amount (ریال)
──────────────────────────────────────────────────────────
Org-291 / RIAL / AVAILABLE            -7,850,000,000   (خریدار پرداخت)
Org-184 / RIAL / AVAILABLE            +7,838,225,000   (فروشنده دریافت)
SYSTEM  / FEE_INCOME                     +11,775,000   (کارمزد فروشنده)
                                       ───────────────
                               مجموع:              0  ✅
```

<div dir="rtl">

---

## ۳.۴ انواع ثبت (Entry Types)

| نوع | asset | جهت | توضیح |
|---|---|---|---|
| `OPENING_BALANCE` | هر دو | + | مانده افتتاحیه هنگام فعال‌سازی عضو |
| `DEPOSIT_GOLD` | GOLD | + | ورود طلای فیزیکی به خزانه |
| `WITHDRAW_GOLD` | GOLD | − | خروج طلای فیزیکی |
| `DEPOSIT_CASH` | RIAL | + | واریز وجه (در مدل نگهداری وجه) |
| `WITHDRAW_CASH` | RIAL | − | برداشت وجه |
| `TRADE_BUY_GOLD` | GOLD | + | دریافت طلا از معامله |
| `TRADE_SELL_GOLD` | GOLD | − | تحویل طلا در معامله |
| `TRADE_BUY_CASH` | RIAL | − | پرداخت وجه در خرید |
| `TRADE_SELL_CASH` | RIAL | + | دریافت وجه در فروش |
| `RESERVE` | هر دو | − | انتقال از AVAILABLE به RESERVED |
| `RELEASE` | هر دو | + | بازگشت از RESERVED به AVAILABLE |
| `FEE_CHARGE` | RIAL | − | کسر کارمزد |
| `FEE_INCOME` | RIAL | + | درآمد کارمزد (حساب سیستمی) |
| `TAX_WITHHOLD` | RIAL | − | کسر مالیات |
| `ASSAY_ADJUSTMENT` | GOLD | ± | تعدیل پس از ری‌گیری مجدد |
| `PROCESSING_LOSS` | GOLD | − | افت ذوب/برش |
| `ROUNDING` | هر دو | ± | تفاوت گِردکردن |
| `NETTING_SETTLE` | هر دو | ± | تسویه از طریق تهاتر |
| `DISPUTE_HOLD` | هر دو | − | قفل بابت اختلاف |
| `DISPUTE_RELEASE` | هر دو | + | آزادسازی پس از حل اختلاف |
| `PENALTY` | RIAL | − | جریمه تأخیر تسویه |
| `REVERSAL` | هر دو | ± | معکوس کردن ثبت اشتباه |
| `MANUAL_ADJUSTMENT` | هر دو | ± | اصلاح دستی (تأیید دوگانه اجباری) |

---

## ۳.۵ چرخه حیات یک مقدار

</div>

```
                        AVAILABLE
                            │
              ┌─────────────┼─────────────┐
              │             │             │
        ثبت سفارش      ثبت اختلاف      برداشت
              │             │             │
              ▼             ▼             ▼
          RESERVED    IN_DISPUTE      (خارج شد)
              │             │
    ┌─────────┼─────────┐   │
    │         │         │   │
 لغو سفارش  معامله   انقضا  حل اختلاف
    │         │         │   │
    │         ▼         │   │
    │   IN_SETTLEMENT   │   │
    │         │         │   │
    │    ┌────┴────┐    │   │
    │    │         │    │   │
    │  تسویه     لغو    │   │
    │    │         │    │   │
    │    ▼         │    │   │
    │ (منتقل شد)   │    │   │
    │              │    │   │
    └──────────────┴────┴───┘
              │
              ▼
          AVAILABLE
```

<div dir="rtl">

هر گذار یک جفت `LedgerEntry` تولید می‌کند (خروج از یک bucket، ورود به bucket دیگر)
با یک `transaction_group` مشترک — بنابراین بقای جرم حفظ می‌شود.

---

## ۳.۶ پیاده‌سازی عملیات اصلی

### رزرو (Reserve)

</div>

```php
public function reserve(
    int $organizationId,
    FineWeight $amount,
    LedgerReference $ref,
): LedgerEntryId {
    return DB::transaction(function () use ($organizationId, $amount, $ref) {
        // قفل هر دو حساب با ترتیب ثابت (جلوگیری از deadlock)
        $accounts = LedgerAccount::whereIn('bucket', ['AVAILABLE', 'RESERVED'])
            ->where('organization_id', $organizationId)
            ->where('asset_type', 'GOLD')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('bucket');

        $available = $this->balanceOf($accounts['AVAILABLE']->id);

        if ($available < $amount->milligrams()) {
            throw new InsufficientBalanceException(
                required: $amount->milligrams(),
                available: $available,
            );
        }

        $group = Str::uuid()->toString();

        $out = $this->writeEntry(
            account: $accounts['AVAILABLE'],
            amount: -$amount->milligrams(),
            type: 'RESERVE',
            group: $group,
            ref: $ref,
        );

        $in = $this->writeEntry(
            account: $accounts['RESERVED'],
            amount: +$amount->milligrams(),
            type: 'RESERVE',
            group: $group,
            ref: $ref,
        );

        $this->assertGroupBalances($group);   // Σ = 0

        return $in->id();
    });
}
```

<div dir="rtl">

### انتقال (Transfer)

</div>

```php
public function transfer(
    int $fromOrgId,
    int $toOrgId,
    FineWeight $amount,
    LedgerReference $ref,
): TransferResult {
    if ($fromOrgId === $toOrgId) {
        throw new SelfTransferException();
    }

    return DB::transaction(function () use (...) {
        // ترتیب ثابت قفل بر اساس organization_id
        [$firstOrg, $secondOrg] = $fromOrgId < $toOrgId
            ? [$fromOrgId, $toOrgId]
            : [$toOrgId, $fromOrgId];

        $this->lockAccountsOf($firstOrg);
        $this->lockAccountsOf($secondOrg);

        $group = Str::uuid()->toString();

        // مبدأ: از IN_SETTLEMENT خارج می‌شود
        $this->writeEntry($fromSettlementAccount, -$mg, 'TRADE_SELL_GOLD', $group, $ref);

        // مقصد: به AVAILABLE اضافه می‌شود
        $this->writeEntry($toAvailableAccount, +$mg, 'TRADE_BUY_GOLD', $group, $ref);

        $this->assertGroupBalances($group);

        return new TransferResult($group, ...);
    }, attempts: 3);
}
```

<div dir="rtl">

### معکوس (Reversal)

</div>

```php
public function reverse(LedgerEntryId $entryId, string $reason): LedgerEntryId
{
    return DB::transaction(function () use ($entryId, $reason) {
        $original = LedgerEntry::lockForUpdate()->findOrFail($entryId->value());

        if ($original->reversed_by_entry_id !== null) {
            throw new AlreadyReversedException($entryId);
        }

        $reversal = $this->writeEntry(
            account: $original->account,
            amount: -$original->amount,          // علامت مخالف
            type: 'REVERSAL',
            group: Str::uuid()->toString(),
            ref: $original->reference(),
            reversesEntryId: $original->id,
            description: "Reversal: {$reason}",
        );

        // فقط این یک ستون روی entry اصلی نوشته می‌شود
        // (استثنای مجاز از قاعده immutability، برای ردیابی)
        $original->forceFill(['reversed_by_entry_id' => $reversal->id])->saveQuietly();

        return $reversal->id();
    });
}
```

<div dir="rtl">

> ⚠️ نکته: نوشتن `reversed_by_entry_id` روی سطر اصلی یک `UPDATE` است که با
> قاعده `REVOKE UPDATE` تضاد دارد. دو راه‌حل:
> **الف)** جدول جداگانه `ledger_reversals(original_id, reversal_id)` — **ترجیحی**
> **ب)** اجازه `UPDATE` فقط روی همان یک ستون با trigger محافظ
>
> **تصمیم: گزینه الف.** جدول `ledger_entries` کاملاً append-only می‌ماند.

---

## ۳.۷ مغایرت‌گیری (Reconciliation)

</div>

```php
// php artisan ledger:reconcile

foreach (LedgerAccount::cursor() as $account) {
    $stored = LedgerBalance::where('account_id', $account->id)->value('balance');

    $computed = LedgerEntry::where('account_id', $account->id)->sum('amount');

    if ($stored !== $computed) {
        event(new BalanceDiscrepancyDetected(
            accountId: $account->id,
            storedBalance: $stored,
            computedBalance: $computed,
            difference: $computed - $stored,
        ));

        // انجماد فوری
        $account->organization->freeze('LEDGER_DISCREPANCY');
    }
}

// بررسی بقای جرم سراسری
$goldSum = LedgerEntry::where('asset_type', 'GOLD')->sum('amount');
if ($goldSum !== 0) {
    Alert::critical("Gold ledger does not balance: {$goldSum} mg");
}

$rialSum = LedgerEntry::where('asset_type', 'RIAL')->sum('amount');
if ($rialSum !== 0) {
    Alert::critical("Rial ledger does not balance: {$rialSum} IRR");
}

// تطبیق دفتر طلا با موجودی فیزیکی
$ledgerGold = LedgerEntry::where('asset_type', 'GOLD')
    ->whereIn('account_id', $memberAccountIds)->sum('amount');

$physicalGold = GoldLot::whereIn('status', ['AVAILABLE','RESERVED','IN_SETTLEMENT'])
    ->sum('fine_weight_mg');

if ($ledgerGold !== $physicalGold) {
    Alert::critical("Ledger/Physical mismatch: {$ledgerGold} vs {$physicalGold}");
}
```

<div dir="rtl">

### زمان‌بندی

| بررسی | تناوب |
|---|---|
| بقای جرم هر `transaction_group` | در لحظه، داخل تراکنش |
| `stored == computed` برای حساب‌های فعال امروز | هر ساعت |
| مغایرت‌گیری کامل همه حساب‌ها | روزانه ۰۲:۰۰ |
| تطبیق دفتر با موجودی فیزیکی | روزانه ۰۲:۳۰ |
| بازبینی زنجیره hash | روزانه ۰۳:۰۰ |
| انبارگردانی فیزیکی واقعی | ماهانه (دستی) |

---

## ۳.۸ Snapshot برای کارایی

با رشد `ledger_entries`، محاسبه `SUM` کند می‌شود.

</div>

```sql
CREATE TABLE ledger_snapshots (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id      BIGINT UNSIGNED NOT NULL,
  snapshot_date   DATE NOT NULL,
  balance         BIGINT NOT NULL,
  last_entry_id   BIGINT UNSIGNED NOT NULL,
  entry_count     BIGINT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NOT NULL,
  UNIQUE KEY uq_account_date (account_id, snapshot_date)
) ENGINE=InnoDB;
```

<div dir="rtl">

بازسازی مانده:

</div>

```sql
SELECT s.balance + COALESCE(SUM(e.amount), 0) AS balance
FROM ledger_snapshots s
LEFT JOIN ledger_entries e
       ON e.account_id = s.account_id
      AND e.id > s.last_entry_id
WHERE s.account_id = ?
  AND s.snapshot_date = (
      SELECT MAX(snapshot_date) FROM ledger_snapshots WHERE account_id = ?
  )
GROUP BY s.balance;
```

<div dir="rtl">

---

## ۳.۹ ثابت‌های الزامی (Invariants)

این‌ها باید در تست خودکار (property-based) بررسی شوند:

</div>

```
I1.  Σ(entries در یک transaction_group، هم asset_type) = 0
I2.  balance ذخیره‌شده = Σ(entries آن حساب)
I3.  balance حساب‌های AVAILABLE/RESERVED/IN_SETTLEMENT ≥ 0
I4.  Σ(همه entries یک asset_type در کل سیستم) = 0
I5.  Σ(GOLD در حساب‌های اعضا) = Σ(fine_weight lotهای فعال)
I6.  هیچ entry حذف نشده (count فقط افزایش می‌یابد)
I7.  هر reverse دقیقاً یک entry با amount معکوس ایجاد کرده
I8.  هیچ entry دوبار reverse نشده
I9.  balance_after هر entry = Σ(entries تا آن نقطه)
I10. زنجیره hash معتبر است
```

<div dir="rtl">

### نمونه تست

</div>

```php
/** @test */
public function total_gold_is_conserved_across_random_operations(): void
{
    $orgs = Organization::factory()->count(10)->active()->create();

    // موجودی اولیه
    foreach ($orgs as $org) {
        $this->ledger->deposit($org->id, FineWeight::fromGrams(1000));
    }

    $initialTotal = $this->totalSystemGold();

    // ۱۰۰۰ عملیات تصادفی
    for ($i = 0; $i < 1000; $i++) {
        $this->performRandomOperation($orgs);
    }

    $this->assertSame(
        $initialTotal,
        $this->totalSystemGold(),
        'Gold was created or destroyed!',
    );
}
```

<div dir="rtl">

---

## ۳.۱۰ ملاحظات همزمانی

### سناریوی خطرناک

</div>

```
زمان    Thread A                      Thread B
─────────────────────────────────────────────────────────
t0      خواندن مانده = 100g
t1                                    خواندن مانده = 100g
t2      بررسی: 100 >= 80  ✓
t3                                    بررسی: 100 >= 80  ✓
t4      ثبت رزرو 80g
t5                                    ثبت رزرو 80g
─────────────────────────────────────────────────────────
نتیجه: 160g رزرو شد در حالی که فقط 100g بود!  ❌
```

<div dir="rtl">

### راه‌حل — قفل بدبینانه

</div>

```
t0      SELECT ... FOR UPDATE  ► قفل گرفت
t1                                    SELECT ... FOR UPDATE ► منتظر
t2      مانده = 100
t3      بررسی ✓
t4      ثبت رزرو 80
t5      COMMIT ► قفل آزاد
t6                                    مانده = 20
t7                                    بررسی: 20 >= 80 ✗
t8                                    InsufficientBalanceException ✅
```

<div dir="rtl">

### جلوگیری از Deadlock

</div>

```
❌ Thread A: قفل org 5 ► قفل org 9
   Thread B: قفل org 9 ► قفل org 5     ◄── deadlock

✅ همیشه به ترتیب صعودی id:
   Thread A: قفل org 5 ► قفل org 9
   Thread B: قفل org 5 ► قفل org 9     ◄── امن
```

<div dir="rtl">

علاوه بر این، `DB::transaction(..., attempts: 3)` تلاش مجدد خودکار روی
deadlock انجام می‌دهد.

---

## ۳.۱۱ رابط کاربری دفتر (نمونه)

</div>

```
┌───────────────────────────────────────────────────────────────────┐
│  دفتر کل طلا — طلافروشی کریمی                    از ۱۴۰۴/۰۸/۰۱   │
├───────────────────────────────────────────────────────────────────┤
│  تاریخ       شرح                    بدهکار    بستانکار    مانده    │
├───────────────────────────────────────────────────────────────────┤
│  08/01 09:00 مانده افتتاحیه              —           —   1,000.000 │
│  08/03 11:20 خرید TRD-88201              —    +250.000   1,250.000 │
│  08/03 11:20 رزرو سفارش ORD-4412    −100.000        —    1,150.000 │
│  08/04 14:05 لغو سفارش ORD-4412          —    +100.000   1,250.000 │
│  08/05 10:15 فروش TRD-88231         −300.000        —      950.000 │
│  08/07 16:40 خرید TRD-88290              —    +500.000   1,450.000 │
│  08/09 09:30 تعدیل ری‌گیری AS-4599    −2.680        —    1,447.320 │
│  08/12 12:00 تحویل فیزیکی WD-221    −200.000        —    1,247.320 │
├───────────────────────────────────────────────────────────────────┤
│  مانده پایانی                                            1,247.320 │
│  ├── در دسترس                                            1,047.320 │
│  ├── رزروشده                                               150.000 │
│  └── در تسویه                                               50.000 │
└───────────────────────────────────────────────────────────────────┘
              [Excel]  [PDF]  [API]  [مغایرت‌گیری]
```

<div dir="rtl">

هر ردیف قابل کلیک است و به سند مبنا (معامله، تسویه، گواهی) می‌رسد.

</div>
