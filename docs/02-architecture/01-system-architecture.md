<div dir="rtl">

# ۱. معماری کلان سیستم

## ۱.۱ نمای کلی

</div>

```
                          ┌──────────────────┐
                          │   Cloudflare /   │
                          │    CDN + WAF     │
                          └────────┬─────────┘
                                   │
                          ┌────────▼─────────┐
                          │   Load Balancer  │
                          │   (nginx / ALB)  │
                          └────────┬─────────┘
                                   │
              ┌────────────────────┼────────────────────┐
              │                    │                    │
     ┌────────▼────────┐  ┌────────▼────────┐  ┌───────▼────────┐
     │  Web App        │  │  Mobile API     │  │  Admin Panel   │
     │  (Inertia/Vue)  │  │  (REST/JSON)    │  │  (Filament)    │
     └────────┬────────┘  └────────┬────────┘  └───────┬────────┘
              │                    │                    │
              └────────────────────┼────────────────────┘
                                   │
                    ┌──────────────▼──────────────┐
                    │      LARAVEL APPLICATION     │
                    │     (Modular Monolith)       │
                    │                              │
                    │  Middleware:                 │
                    │   Auth · RateLimit ·         │
                    │   Idempotency · Audit        │
                    └──────────────┬───────────────┘
                                   │
       ┌───────────────────────────┼───────────────────────────┐
       │                           │                           │
┌──────▼──────┐  ┌─────────────────▼────────┐  ┌──────────────▼─────┐
│  IDENTITY   │  │      TRADING             │  │      LEDGER        │
│  Org · User │  │  Order · Match · RFQ     │  │  Gold · Rial       │
│  KYC · RBAC │  │  OTC · Session           │  │  Entry · Balance   │
└─────────────┘  └──────────────────────────┘  └────────────────────┘
       │                           │                           │
┌──────▼──────┐  ┌─────────────────▼────────┐  ┌──────────────▼─────┐
│  SETTLEMENT │  │      PRICING             │  │      RISK          │
│  Clearing   │  │  Feed · Quote · OHLC     │  │  Limit · Score     │
│  Netting    │  │                          │  │  Flag · AML        │
└─────────────┘  └──────────────────────────┘  └────────────────────┘
       │                           │                           │
┌──────▼──────┐  ┌─────────────────▼────────┐  ┌──────────────▼─────┐
│  CUSTODY    │  │      ACCOUNTING          │  │   NOTIFICATION     │
│  Lot·Vault  │  │  Chart · Journal         │  │   Push·SMS·InApp   │
│  Assay      │  │  Report                  │  │                    │
└─────────────┘  └──────────────────────────┘  └────────────────────┘
                                   │
                    ┌──────────────▼──────────────┐
                    │        EVENT BUS             │
                    │  (Laravel Events + Queue)    │
                    └──────────────┬───────────────┘
                                   │
       ┌───────────────┬───────────┼───────────┬───────────────┐
       ▼               ▼           ▼           ▼               ▼
┌────────────┐ ┌────────────┐ ┌─────────┐ ┌─────────┐ ┌──────────────┐
│  MySQL 8   │ │  Redis 7   │ │   S3    │ │ Reverb  │ │  Prometheus  │
│  Primary   │ │ Cache·Lock │ │ Storage │ │   WS    │ │  + Grafana   │
│  + Replica │ │ Queue·Rate │ │  (KYC)  │ │         │ │  + Loki      │
└────────────┘ └────────────┘ └─────────┘ └─────────┘ └──────────────┘
```

<div dir="rtl">

---

## ۱.۲ چرا Modular Monolith و نه Microservices؟

| معیار | تصمیم |
|---|---|
| **تراکنش اتمیک** | معامله + دفتر طلا + دفتر ریال + کارمزد باید در یک تراکنش دیتابیس اتفاق بیفتد. با میکروسرویس نیازمند Saga و Compensation می‌شویم که در سیستم مالی ریسک بالایی دارد |
| **اندازه تیم** | تیم ۴–۸ نفره؛ سربار عملیاتی میکروسرویس توجیه ندارد |
| **بلوغ دامنه** | مرزهای دامنه هنوز در حال تثبیت است |
| **مسیر خروج** | مرزبندی سخت ماژول‌ها از ابتدا رعایت می‌شود تا استخراج بعدی ممکن باشد |

**قواعد مرزبندی (اجباری):**

</div>

```
❌ ماژول A نباید مستقیماً به Model ماژول B دسترسی داشته باشد
✅ ارتباط فقط از طریق:
   ۱) Public Service Interface ماژول مقصد
   ۲) Domain Event

❌ ماژول A نباید جدول ماژول B را JOIN کند
✅ اگر لازم است، ماژول B متد Query عمومی ارائه می‌دهد

✅ استثنا: ماژول‌های Shared (Money, Weight, AuditLog)
```

این قاعده با ابزار static analysis (deptrac) در CI اعمال می‌شود.

---

## ۱.۳ ماژول‌های هسته و مسئولیت آن‌ها

| ماژول | مسئولیت | مالک داده |
|---|---|---|
| `Identity` | سازمان، کاربر، نقش، مجوز، نشست | `organizations`, `users`, `roles` |
| `Kyc` | اسناد، بررسی، وضعیت انطباق | `kyc_*`, `documents`, `licenses` |
| `Custody` | GoldLot، گواهی، خزانه، نگهداری | `gold_lots`, `assays`, `vaults` |
| `Ledger` | دفتر کل طلا و ریال | `ledger_accounts`, `ledger_entries` |
| `Trading` | سفارش، تطبیق، RFQ، OTC، معامله | `orders`, `trades`, `rfq_*` |
| `Settlement` | پایاپای، تسویه، تهاتر | `settlements`, `netting_*` |
| `Pricing` | فید قیمت، مظنه، OHLC | `prices`, `price_sources` |
| `Risk` | سقف، امتیاز، Flag، AML | `risk_*`, `limits`, `flags` |
| `Accounting` | چارت حساب، ثبت دوطرفه، گزارش | `accounts`, `journal_entries` |
| `Dispute` | اختلاف، مدارک، رأی | `disputes`, `evidences` |
| `Notification` | اعلان چندکاناله | `notifications` |
| `Reporting` | گزارش‌ساز و خروجی | (read-only) |
| `Shared` | Value Object، Audit، Idempotency | `audit_logs`, `idempotency_keys` |

---

## ۱.۴ جریان داده در یک معامله (نمای فنی)

</div>

```
POST /api/v1/orders
      │
      ▼
┌─────────────────────────────────────────┐
│ Middleware                              │
│  ├── auth:sanctum                       │
│  ├── organization.active                │
│  ├── throttle:orders                    │
│  └── idempotency                        │
└──────────────────┬──────────────────────┘
                   ▼
┌─────────────────────────────────────────┐
│ OrderController::store                  │
│  └── CreateOrderRequest (validation)    │
└──────────────────┬──────────────────────┘
                   ▼
┌─────────────────────────────────────────┐
│ Trading\Services\PlaceOrderService      │
│                                         │
│  DB::transaction(function () {          │
│    ۱. Risk::assertWithinLimits()        │
│    ۲. Calc::computeRequirement()        │
│    ۳. Ledger::reserve()   ◄── قفل        │
│    ۴. Order::create()                   │
│    ۵. MatchingEngine::tryMatch()        │
│    ۶. Trade::create()   (اگر match شد)   │
│    ۷. Settlement::open()                │
│  });                                    │
│                                         │
│  event(new OrderPlaced($order))         │
│  event(new TradeExecuted($trade))       │
└──────────────────┬──────────────────────┘
                   ▼
┌─────────────────────────────────────────┐
│ Event Listeners (async, queued)         │
│  ├── BroadcastOrderBookUpdate  → WS     │
│  ├── SendTradeNotification     → Push   │
│  ├── RecordAccountingEntry     → Journal│
│  ├── UpdateReputationStats               │
│  ├── EvaluateAmlRules                    │
│  └── WriteAuditLog                       │
└─────────────────────────────────────────┘
```

<div dir="rtl">

**قاعده حیاتی:**
داخل تراکنش فقط عملیات **مالی و اتمیک**. همه‌چیز دیگر (اعلان، broadcast،
حسابداری تحلیلی، AML) به‌صورت **رویداد صف‌شده** بعد از commit اجرا می‌شود.

</div>

```php
// ❌ اشتباه — اعلان داخل تراکنش
DB::transaction(function () use ($order) {
    $trade = $this->match($order);
    Notification::send(...);   // اگر صف کند شود، قفل دیتابیس باز نمی‌شود
});

// ✅ درست
$trade = DB::transaction(fn () => $this->match($order));
event(new TradeExecuted($trade));   // پس از commit
```

<div dir="rtl">

---

## ۱.۵ استراتژی همزمانی (Concurrency)

مهم‌ترین ریسک فنی این سیستم: **دو معامله همزمان روی یک موجودی**.

### سه لایه دفاع

**لایه ۱ — قفل بدبینانه روی حساب دفتر**

</div>

```sql
-- همیشه با ترتیب ثابت (کوچک‌ترین account_id اول) تا از deadlock جلوگیری شود
SELECT * FROM ledger_accounts
WHERE id IN (?, ?)
ORDER BY id
FOR UPDATE;
```

<div dir="rtl">

**لایه ۲ — قفل توزیع‌شده Redis برای عملیات چندمرحله‌ای**

</div>

```php
Cache::lock("ledger:org:{$orgId}:gold", 10)->block(5, function () {
    // عملیات
});
```

<div dir="rtl">

**لایه ۳ — محدودیت پایگاه‌داده به‌عنوان آخرین خط دفاع**

</div>

```sql
-- مانده هرگز منفی نشود
ALTER TABLE ledger_balances
  ADD CONSTRAINT chk_available_non_negative
  CHECK (available_amount >= 0);
```

<div dir="rtl">

### ترتیب قفل‌گذاری (Lock Ordering) — الزامی

برای جلوگیری از bloqueio متقابل، قفل‌ها **همیشه** به این ترتیب گرفته می‌شوند:

</div>

```
۱. Organization (بر اساس id صعودی)
۲. LedgerAccount (بر اساس id صعودی)
۳. Order (بر اساس id صعودی)
۴. GoldLot (بر اساس id صعودی)
```

<div dir="rtl">

---

## ۱.۶ Matching Engine — طراحی

### فاز ۲: مبتنی بر دیتابیس

</div>

```
سفارش خرید جدید (Limit، قیمت P، حجم Q)
        │
        ▼
BEGIN TRANSACTION
        │
        ▼
SELECT * FROM orders
WHERE side = 'SELL'
  AND instrument_id = ?
  AND status = 'OPEN'
  AND price <= P
  AND organization_id != ?        ◄── جلوگیری از Self-Trade
ORDER BY price ASC, created_at ASC  ◄── price-time priority
FOR UPDATE SKIP LOCKED
LIMIT 50
        │
        ▼
حلقه تطبیق تا پر شدن Q یا پایان سفارش‌ها
        │
        ▼
ایجاد Trade برای هر تطبیق
        │
        ▼
COMMIT
```

<div dir="rtl">

`SKIP LOCKED` اجازه می‌دهد چند worker همزمان بدون انتظار کار کنند.

### فاز ۳ (در صورت نیاز): موتور درون‌حافظه‌ای

اگر throughput از ~۵۰۰ سفارش بر ثانیه گذشت:
- یک process تک‌رشته‌ای مالک Order Book در حافظه
- ورودی از صف، خروجی به دیتابیس با Event Sourcing
- امکان بازسازی کامل کتاب از روی رویدادها

تصمیم و شرایط دقیق در [`05-adr.md#adr-007`](05-adr.md).

---

## ۱.۷ استراتژی دیتابیس

</div>

```
┌────────────────────────────────────────────────┐
│  MySQL 8.0 Primary                             │
│  ├── تمام نوشتن‌ها                              │
│  ├── خواندن‌های حساس (دفتر، تطبیق)              │
│  └── ISOLATION: REPEATABLE READ (پیش‌فرض)      │
└──────────────────┬─────────────────────────────┘
                   │ replication
                   ▼
┌────────────────────────────────────────────────┐
│  MySQL 8.0 Read Replica                        │
│  ├── گزارش‌ها                                   │
│  ├── داشبورد                                    │
│  └── خواندن‌های غیرحساس                         │
└────────────────────────────────────────────────┘
```

<div dir="rtl">

> ⚠️ هرگز مانده دفتر را از replica نخوانید. تأخیر replication می‌تواند
> باعث فروش دو‌بارهٔ یک موجودی شود.

در Laravel با `DB::connection('mysql')` برای نوشتن/حساس و
`DB::connection('mysql_read')` برای گزارش.

---

## ۱.۸ Realtime

</div>

```
Matching Engine
      │
      ▼
event(OrderBookChanged)
      │
      ▼
Laravel Reverb (WebSocket)
      │
      ├── channel: market.{instrument}          ← عمومی، قیمت و عمق بازار
      ├── channel: private-org.{orgId}          ← سفارش‌ها و معاملات عضو
      └── channel: private-org.{orgId}.settle   ← وضعیت تسویه
```

<div dir="rtl">

**قواعد:**
- کانال عمومی هرگز نباید هویت طرف معامله را افشا کند
- کانال خصوصی با `Broadcast::channel()` و بررسی `organization_id` محافظت می‌شود
- Throttle روی broadcast: حداکثر ۱۰ به‌روزرسانی در ثانیه برای عمق بازار

---

## ۱.۹ نمای استقرار

</div>

```
┌─────────────────── Production ────────────────────┐
│                                                   │
│  ┌─────────┐  ┌─────────┐   app servers (≥2)      │
│  │  app-1  │  │  app-2  │   PHP-FPM + nginx       │
│  └─────────┘  └─────────┘                         │
│                                                   │
│  ┌─────────┐  ┌─────────┐   queue workers         │
│  │worker-1 │  │worker-2 │   ├── default            │
│  └─────────┘  └─────────┘   ├── settlement (اولویت)│
│                             ├── notification       │
│  ┌─────────┐                └── reporting          │
│  │ scheduler│  (cron)                              │
│  └─────────┘                                       │
│                                                   │
│  ┌─────────┐  ┌─────────┐  ┌─────────┐            │
│  │ MySQL   │  │  Redis  │  │ Reverb  │            │
│  │ Pri+Rep │  │ Sentinel│  │   WS    │            │
│  └─────────┘  └─────────┘  └─────────┘            │
└───────────────────────────────────────────────────┘
```

<div dir="rtl">

جزئیات در [`09-operations/01-deployment.md`](../09-operations/01-deployment.md).

---

## ۱.۱۰ ملاحظات مقیاس‌پذیری

| مؤلفه | گلوگاه احتمالی | راهکار |
|---|---|---|
| `ledger_entries` | رشد سریع جدول | پارتیشن‌بندی ماهانه بر اساس `created_at` |
| Matching | قفل روی سفارش‌ها | `SKIP LOCKED` + partition بر اساس instrument |
| گزارش‌ها | فشار روی primary | replica + جدول‌های snapshot روزانه |
| WebSocket | تعداد اتصال | Reverb افقی + Redis backplane |
| فایل‌های KYC | حجم | S3 + lifecycle policy |

</div>
