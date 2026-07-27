<div dir="rtl">

# ۱. مدل داده و ERD

## ۱.۱ نمای کلان موجودیت‌ها

</div>

```
                        ┌──────────────────┐
                        │  ORGANIZATION    │◄─────────┐
                        │  عضو صنفی        │          │
                        └────────┬─────────┘          │
                                 │                    │
        ┌────────────────────────┼────────────────────┼──────────┐
        │                        │                    │          │
        ▼                        ▼                    │          ▼
  ┌──────────┐            ┌─────────────┐             │   ┌─────────────┐
  │  USER    │            │  KYC        │             │   │RISK_PROFILE │
  │  BRANCH  │            │  DOCUMENT   │             │   │  LIMITS     │
  │  ROLE    │            │  LICENSE    │             │   │  FLAGS      │
  └──────────┘            │  BANK_ACCT  │             │   └─────────────┘
                          │  REPRESENT. │             │
                          └─────────────┘             │
                                                      │
  ┌───────────────────────────────────────────────────┤
  │                                                   │
  ▼                                                   │
┌──────────────────┐         ┌──────────────────┐    │
│  LEDGER_ACCOUNT  │◄────────│  LEDGER_ENTRY    │    │
│  (GOLD | RIAL)   │         │  append-only     │    │
│  × bucket        │         └──────────────────┘    │
└────────┬─────────┘                  ▲              │
         │                            │              │
         ▼                            │              │
┌──────────────────┐                  │              │
│ LEDGER_BALANCE   │                  │              │
│ (cache)          │                  │              │
└──────────────────┘                  │              │
                                      │              │
  ┌───────────────────────────────────┼──────────────┤
  │                                   │              │
  ▼                                   │              │
┌──────────────┐   ┌──────────────┐  │              │
│  INSTRUMENT  │◄──│    ORDER     │──┘              │
└──────┬───────┘   └──────┬───────┘                 │
       │                  │                          │
       │                  ▼                          │
       │           ┌──────────────┐                  │
       └──────────►│    TRADE     │◄─────────────────┘
                   └──────┬───────┘
                          │
                          ▼
                   ┌──────────────┐        ┌──────────────┐
                   │  SETTLEMENT  │───────►│   DISPUTE    │
                   └──────┬───────┘        └──────────────┘
                          │
              ┌───────────┼───────────┐
              ▼           ▼           ▼
      ┌─────────────┐ ┌────────┐ ┌──────────────┐
      │NETTING_BATCH│ │PAYMENT │ │GOLD_TRANSFER │
      └─────────────┘ └────────┘ └──────┬───────┘
                                        │
                                        ▼
                                 ┌──────────────┐
                                 │  GOLD_LOT    │
                                 └──────┬───────┘
                                        │
              ┌─────────────────────────┼──────────────────┐
              ▼                         ▼                  ▼
      ┌──────────────┐         ┌──────────────┐   ┌──────────────┐
      │    ASSAY     │         │ LOT_LINEAGE  │   │    VAULT     │
      │  LABORATORY  │         │  (genealogy) │   │  SAFE/SHELF  │
      │   REFINER    │         └──────────────┘   │     BOX      │
      └──────────────┘                            └──────────────┘
```

<div dir="rtl">

---

## ۱.۲ گروه‌بندی جداول به تفکیک ماژول

### ماژول Identity (۹ جدول)

</div>

```
organizations
  ├── branches
  ├── users
  │     ├── user_sessions
  │     ├── user_devices
  │     └── user_two_factor
  ├── roles
  ├── permissions
  ├── role_user
  ├── permission_role
  └── representatives
```

<div dir="rtl">

### ماژول Kyc (۶ جدول)

</div>

```
kyc_profiles
business_licenses
bank_accounts
documents
kyc_reviews
signatories
```

<div dir="rtl">

### ماژول Custody (۱۲ جدول)

</div>

```
gold_lots
assays
laboratories
refiners
lot_lineage
custody_operations
custody_records
vaults
vault_safes
vault_shelves
vault_boxes
vault_audits
```

<div dir="rtl">

### ماژول Ledger (۵ جدول)

</div>

```
ledger_accounts
ledger_entries          ◄── append-only، پارتیشن‌بندی‌شده
ledger_balances         ◄── کش
ledger_snapshots
ledger_reversals
```

<div dir="rtl">

### ماژول Trading (۹ جدول)

</div>

```
instruments
market_sessions
orders
order_fills
trades
otc_offers
otc_offer_history
rfqs
rfq_quotes
```

<div dir="rtl">

### ماژول Settlement (۷ جدول)

</div>

```
settlements
settlement_events
payments
gold_transfers
netting_batches
netting_positions
netting_settlements
```

<div dir="rtl">

### ماژول Pricing (۵ جدول)

</div>

```
price_sources
price_ticks
reference_prices
market_quotes
price_candles
price_alerts
```

<div dir="rtl">

### ماژول Risk (۷ جدول)

</div>

```
risk_profiles
trading_limits
user_limits
daily_counters
collaterals
aml_rules
aml_flags
```

<div dir="rtl">

### ماژول Accounting (۴ جدول)

</div>

```
chart_of_accounts
journal_entries
journal_lines
inventory_cost_basis
```

<div dir="rtl">

### ماژول Counterparty (۲ جدول)

</div>

```
counterparty_relations
balance_confirmations
```

<div dir="rtl">

### ماژول Dispute (۳ جدول)

</div>

```
disputes
dispute_evidences
dispute_timeline
```

<div dir="rtl">

### ماژول Reputation (۲ جدول)

</div>

```
reputation_stats
reputation_periods
```

<div dir="rtl">

### ماژول Notification (۳ جدول)

</div>

```
notifications
notification_deliveries
notification_preferences
```

<div dir="rtl">

### ماژول Shared (۵ جدول)

</div>

```
audit_logs              ◄── append-only، پارتیشن‌بندی‌شده
idempotency_keys
business_calendar
system_settings
failed_events
```

<div dir="rtl">

### گزارش‌گیری (۳ جدول)

</div>

```
daily_org_summary
daily_platform_summary
report_jobs
```

<div dir="rtl">

**جمع: حدود ۸۲ جدول**

---

## ۱.۳ روابط کلیدی و کاردینالیتی

</div>

```
organizations 1 ──── N users
organizations 1 ──── N branches
organizations 1 ──── 1 kyc_profiles
organizations 1 ──── N business_licenses      (تاریخچه)
organizations 1 ──── N bank_accounts
organizations 1 ──── N representatives
organizations 1 ──── 1 risk_profiles
organizations 1 ──── N ledger_accounts        (asset × bucket)
organizations 1 ──── N gold_lots              (به‌عنوان owner)
organizations 1 ──── N orders
organizations 1 ──── N counterparty_relations
organizations 1 ──── 1 reputation_stats
organizations 1 ──── 1 inventory_cost_basis

ledger_accounts 1 ── N ledger_entries
ledger_accounts 1 ── 1 ledger_balances
ledger_accounts 1 ── N ledger_snapshots

instruments 1 ─────── N orders
instruments 1 ─────── N trades
instruments 1 ─────── N market_sessions
instruments 1 ─────── 1 market_quotes
instruments 1 ─────── N price_candles

orders 1 ──────────── N order_fills
orders N ──────────── N trades               (از طریق buy/sell_order_id)

trades 1 ──────────── 1 settlements
trades 1 ──────────── N disputes

settlements 1 ─────── N settlement_events
settlements 1 ─────── N payments
settlements 1 ─────── N gold_transfers
settlements N ─────── N netting_batches      (netting_settlements)

gold_lots 1 ───────── N assays               (تاریخچه ری‌گیری)
gold_lots N ───────── N gold_lots            (lot_lineage — والد/فرزند)
gold_lots N ───────── 1 vault_boxes
gold_lots 1 ───────── N custody_records

vaults 1 ──────────── N vault_safes
vault_safes 1 ─────── N vault_shelves
vault_shelves 1 ───── N vault_boxes

laboratories 1 ────── N assays
refiners 1 ────────── N gold_lots

rfqs 1 ────────────── N rfq_quotes
rfq_quotes 1 ──────── 0..1 trades

disputes 1 ────────── N dispute_evidences
disputes 1 ────────── N dispute_timeline

journal_entries 1 ─── N journal_lines
```

<div dir="rtl">

---

## ۱.۴ قواعد نام‌گذاری

</div>

```
جدول:        جمع، snake_case            ► gold_lots, ledger_entries
کلید اصلی:   id (BIGINT UNSIGNED AI)
کلید خارجی:  {table_singular}_id         ► organization_id, gold_lot_id
              مگر معنایی متفاوت باشد     ► buyer_organization_id
بولی:        is_/has_/can_               ► is_active, has_collateral
تاریخ:       {verb}_at                   ► created_at, settled_at
شمارنده:     {noun}_count                ► trade_count
مبلغ ریالی:  {noun}_rial                 ► gross_amount_rial
وزن:         {noun}_mg                   ► fine_weight_mg
درصد:        {noun}_bps یا _x100k        ► rate_x100k
enum:        UPPER_SNAKE در مقدار        ► 'PARTIALLY_FILLED'
ایندکس:      idx_{ستون‌ها}               ► idx_org_created
یکتا:        uq_{ستون‌ها}                ► uq_org_asset_bucket
constraint:  chk_{شرح}                   ► chk_amount_positive
```

<div dir="rtl">

---

## ۱.۵ ستون‌های استاندارد

هر جدول اصلی این ستون‌ها را دارد:

</div>

```sql
id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP
created_by_user_id BIGINT UNSIGNED NULL      -- برای جداول عملیاتی
```

<div dir="rtl">

**استثناها:**
- `ledger_entries` و `audit_logs`: بدون `updated_at` (تغییرناپذیر)
- جداول pivot: بدون `id` مستقل در برخی موارد

**بدون Soft Delete:** این سیستم `deleted_at` ندارد. هیچ رکورد مالی
حذف نمی‌شود. برای جداول پیکربندی از `status` استفاده می‌شود.

---

## ۱.۶ استراتژی ایندکس‌گذاری

### اصول

</div>

```
۱) هر کلید خارجی ایندکس دارد
۲) هر ستونی که در WHERE پرتکرار است ایندکس دارد
۳) ایندکس ترکیبی به ترتیب انتخاب‌پذیری (selectivity)
۴) ایندکس پوششی (covering) برای کوئری‌های داغ
۵) اجتناب از ایندکس روی ستون‌های با کاردینالیتی پایین به‌تنهایی
```

<div dir="rtl">

### ایندکس‌های حیاتی

</div>

```sql
-- Order Book — داغ‌ترین کوئری سیستم
CREATE INDEX idx_orderbook ON orders
    (instrument_id, side, status, price_rial, placed_at);

-- دفتر کل — کوئری مانده
CREATE INDEX idx_ledger_account_time ON ledger_entries
    (account_id, created_at);

-- دفتر کل — یافتن ثبت‌های یک معامله
CREATE INDEX idx_ledger_reference ON ledger_entries
    (reference_type, reference_id);

-- تسویه — صف کار کاربر
CREATE INDEX idx_settlement_pending ON settlements
    (cash_payer_org_id, status, deadline_at);
CREATE INDEX idx_settlement_receiver ON settlements
    (cash_receiver_org_id, status, deadline_at);

-- خزانه — lotهای در دسترس یک مالک
CREATE INDEX idx_lot_owner_status ON gold_lots
    (owner_organization_id, status, purity_x10);

-- تخصیص lot — یافتن سریع
CREATE INDEX idx_lot_allocation ON gold_lots
    (owner_organization_id, status, custodian_type, fine_weight_mg);

-- Audit — جستجوی رایج
CREATE INDEX idx_audit_org_time ON audit_logs
    (organization_id, occurred_at);
CREATE INDEX idx_audit_subject ON audit_logs
    (subject_type, subject_id);
```

<div dir="rtl">

---

## ۱.۷ استراتژی پارتیشن‌بندی

جداول با رشد سریع:

| جدول | کلید پارتیشن | دوره | نگهداری |
|---|---|---|---|
| `ledger_entries` | `created_at` | ماهانه | دائمی (بایگانی سرد پس از ۲ سال) |
| `audit_logs` | `occurred_at` | ماهانه | ۱۰ سال |
| `price_ticks` | `received_at` | هفتگی | ۹۰ روز، سپس تجمیع |
| `notifications` | `created_at` | ماهانه | ۱ سال |
| `trades` | `executed_at` | ماهانه | دائمی |

</div>

```sql
-- نمونه: ایجاد پارتیشن ماه آینده به‌صورت خودکار
-- php artisan db:manage-partitions  (ماهانه)

ALTER TABLE ledger_entries
  REORGANIZE PARTITION pmax INTO (
    PARTITION p2026_08 VALUES LESS THAN (UNIX_TIMESTAMP('2026-09-01')),
    PARTITION pmax VALUES LESS THAN MAXVALUE
  );
```

<div dir="rtl">

> ⚠️ محدودیت MySQL: در جدول پارتیشن‌شده، کلید پارتیشن باید در **همه**
> کلیدهای یکتا (شامل PRIMARY KEY) حضور داشته باشد.
> بنابراین: `PRIMARY KEY (id, created_at)` نه `PRIMARY KEY (id)`.
>
> همچنین **Foreign Key پشتیبانی نمی‌شود** روی جداول پارتیشن‌شده.
> یکپارچگی ارجاعی در اپلیکیشن اعمال می‌شود.

---

## ۱.۸ تنظیمات دیتابیس

</div>

```sql
-- ایجاد دیتابیس
CREATE DATABASE goldb2b
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;
```

<div dir="rtl">

</div>

```ini
# my.cnf — تنظیمات پیشنهادی

[mysqld]
default_storage_engine          = InnoDB
character_set_server            = utf8mb4
collation_server                = utf8mb4_0900_ai_ci

# تراکنش
transaction_isolation           = REPEATABLE-READ
innodb_flush_log_at_trx_commit  = 1        # ایمنی مالی — بدون مصالحه
sync_binlog                     = 1

# حافظه
innodb_buffer_pool_size         = 70% RAM
innodb_log_file_size            = 2G

# قفل
innodb_lock_wait_timeout        = 10       # کوتاه — سریع fail کن
innodb_deadlock_detect          = ON
innodb_print_all_deadlocks      = ON       # برای دیباگ

# اتصالات
max_connections                 = 500
wait_timeout                    = 300

# لاگ
slow_query_log                  = 1
long_query_time                 = 0.5
log_queries_not_using_indexes   = 1

# replication
binlog_format                   = ROW
gtid_mode                       = ON
enforce_gtid_consistency        = ON
```

<div dir="rtl">

**`innodb_flush_log_at_trx_commit = 1` غیرقابل مذاکره است.**
مقدار `2` سریع‌تر است اما در قطع برق می‌تواند تراکنش commit شده را
از دست بدهد — در سیستم مالی غیرقابل قبول.

---

## ۱.۹ کاربران دیتابیس و مجوزها

</div>

```sql
-- کاربر اپلیکیشن — بدون DDL
CREATE USER 'app'@'%' IDENTIFIED BY '...';
GRANT SELECT, INSERT, UPDATE, DELETE ON goldb2b.* TO 'app'@'%';

-- محدودیت روی جداول append-only
REVOKE UPDATE, DELETE ON goldb2b.ledger_entries FROM 'app'@'%';
REVOKE UPDATE, DELETE ON goldb2b.audit_logs FROM 'app'@'%';
REVOKE UPDATE, DELETE ON goldb2b.lot_lineage FROM 'app'@'%';
REVOKE DELETE ON goldb2b.trades FROM 'app'@'%';
REVOKE DELETE ON goldb2b.settlements FROM 'app'@'%';
REVOKE DELETE ON goldb2b.gold_lots FROM 'app'@'%';

-- کاربر مهاجرت — فقط در زمان deploy
CREATE USER 'migrator'@'localhost' IDENTIFIED BY '...';
GRANT ALL ON goldb2b.* TO 'migrator'@'localhost';

-- کاربر گزارش — فقط خواندن از replica
CREATE USER 'reporter'@'%' IDENTIFIED BY '...';
GRANT SELECT ON goldb2b.* TO 'reporter'@'%';

-- حسابرس — فقط خواندن، با ثبت
CREATE USER 'auditor'@'%' IDENTIFIED BY '...';
GRANT SELECT ON goldb2b.* TO 'auditor'@'%';
```

<div dir="rtl">

---

## ۱.۱۰ نمودار ERD تفصیلی — هسته مالی

</div>

```
┌─────────────────────────┐
│      organizations      │
│─────────────────────────│
│ PK id                   │
│    type                 │
│    status               │
│    display_name         │
│    national_id_enc      │
│    national_id_hash  UQ │
│    city                 │
│    risk_level           │
└───────────┬─────────────┘
            │ 1
            │
            │ N
┌───────────▼─────────────┐         ┌─────────────────────────┐
│    ledger_accounts      │    1    │    ledger_balances      │
│─────────────────────────│◄────────│─────────────────────────│
│ PK id                   │    1    │ PK account_id           │
│ FK organization_id      │         │    balance              │
│    asset_type           │         │    last_entry_id        │
│    metal_type           │         │    entry_count          │
│    bucket               │         │    version              │
│ UQ (org,asset,metal,    │         └─────────────────────────┘
│     bucket)             │
└───────────┬─────────────┘
            │ 1
            │
            │ N
┌───────────▼──────────────────────────────────┐
│              ledger_entries                   │
│──────────────────────────────────────────────│
│ PK (id, created_at)      ◄── پارتیشن           │
│ FK account_id                                 │
│    organization_id                            │
│    asset_type                                 │
│    amount            BIGINT (± )              │
│    entry_type                                 │
│    reference_type                             │
│    reference_id                               │
│    transaction_group CHAR(36)  ◄── گروه‌بندی   │
│    balance_after                              │
│    prev_hash / row_hash                       │
│    created_at        TIMESTAMP(6)             │
│                                               │
│  ⛔ NO UPDATE, NO DELETE                       │
└───────────────────────────────────────────────┘
            ▲
            │ reverses
            │
┌───────────┴─────────────┐
│    ledger_reversals     │
│─────────────────────────│
│ PK id                   │
│ FK original_entry_id UQ │
│ FK reversal_entry_id UQ │
│    reason               │
│    approved_by_user_id  │
└─────────────────────────┘
```

</div>
