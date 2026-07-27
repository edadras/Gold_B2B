<div dir="rtl">

# ۲. اسکیمای MySQL

> این سند تعریف DDL جداول اصلی است. جداول کم‌اهمیت‌تر در اسناد دامنه
> مربوطه تعریف شده‌اند. همه جداول `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`.

---

## ۲.۱ Identity

</div>

```sql
CREATE TABLE organizations (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type                ENUM('INDIVIDUAL','LEGAL_ENTITY') NOT NULL,
  status              ENUM('PENDING','UNDER_REVIEW','INFO_REQUIRED','VERIFIED',
                           'ACTIVE','RESTRICTED','SUSPENDED','CLOSING','CLOSED',
                           'REJECTED') NOT NULL DEFAULT 'PENDING',
  display_name        VARCHAR(191) NOT NULL,
  legal_name          VARCHAR(191) NULL,

  -- هویت (رمزنگاری‌شده + hash برای جستجو)
  national_id_enc     VARBINARY(255) NULL,
  national_id_hash    CHAR(64) NULL,
  legal_id_enc        VARBINARY(255) NULL,
  legal_id_hash       CHAR(64) NULL,
  registration_no     VARCHAR(50) NULL,
  established_at      DATE NULL,

  -- صنفی
  union_name          VARCHAR(191) NULL,
  city                VARCHAR(100) NOT NULL,
  province            VARCHAR(100) NULL,
  market_name         VARCHAR(191) NULL,
  address             VARCHAR(500) NULL,
  postal_code         CHAR(10) NULL,

  -- تماس
  phone               VARCHAR(20) NULL,
  mobile              VARCHAR(20) NOT NULL,
  email               VARCHAR(191) NULL,
  website             VARCHAR(191) NULL,

  -- وضعیت
  risk_level          ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'MEDIUM',
  compliance_state    ENUM('NORMAL','MONITORED','ENHANCED_DUE_DILIGENCE')
                        NOT NULL DEFAULT 'NORMAL',

  activated_at        TIMESTAMP NULL,
  restricted_at       TIMESTAMP NULL,
  suspended_at        TIMESTAMP NULL,
  closed_at           TIMESTAMP NULL,
  restriction_reason  VARCHAR(500) NULL,

  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_national_id (national_id_hash),
  UNIQUE KEY uq_legal_id (legal_id_hash),
  UNIQUE KEY uq_mobile (mobile),
  KEY idx_status (status),
  KEY idx_city (city),
  KEY idx_risk (risk_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

<div dir="rtl">

</div>

```sql
CREATE TABLE users (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id       BIGINT UNSIGNED NOT NULL,
  branch_id             BIGINT UNSIGNED NULL,

  full_name             VARCHAR(191) NOT NULL,
  mobile                VARCHAR(20) NOT NULL,
  email                 VARCHAR(191) NULL,
  national_id_enc       VARBINARY(255) NULL,
  national_id_hash      CHAR(64) NULL,

  password_hash         VARCHAR(255) NOT NULL,
  password_changed_at   TIMESTAMP NULL,

  two_factor_secret_enc VARBINARY(255) NULL,
  two_factor_confirmed_at TIMESTAMP NULL,
  two_factor_recovery_enc VARBINARY(1000) NULL,

  status                ENUM('PENDING','ACTIVE','SUSPENDED','DISABLED')
                          NOT NULL DEFAULT 'PENDING',

  last_login_at         TIMESTAMP NULL,
  last_login_ip         VARBINARY(16) NULL,
  failed_login_count    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until          TIMESTAMP NULL,

  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_mobile (mobile),
  KEY idx_organization (organization_id),
  KEY idx_status (status),
  CONSTRAINT fk_users_org FOREIGN KEY (organization_id)
    REFERENCES organizations (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

<div dir="rtl">

---

## ۲.۲ Ledger — مهم‌ترین جداول

</div>

```sql
CREATE TABLE ledger_accounts (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id   BIGINT UNSIGNED NOT NULL,   -- 0 = حساب سیستمی
  asset_type        ENUM('GOLD','RIAL') NOT NULL,
  metal_type        ENUM('GOLD','SILVER','PLATINUM') NULL,
  bucket            ENUM('AVAILABLE','RESERVED','IN_SETTLEMENT',
                         'IN_DISPUTE','PAYABLE') NOT NULL,
  system_account_code VARCHAR(50) NULL,          -- برای حساب‌های سیستمی
  currency          CHAR(3) NULL DEFAULT 'IRR',
  allows_negative   BOOLEAN NOT NULL DEFAULT FALSE,
  status            ENUM('ACTIVE','FROZEN','CLOSED') NOT NULL DEFAULT 'ACTIVE',
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_org_asset_bucket (organization_id, asset_type, metal_type, bucket),
  KEY idx_organization (organization_id),
  KEY idx_system_code (system_account_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

<div dir="rtl">

</div>

```sql
CREATE TABLE ledger_entries (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id          BIGINT UNSIGNED NOT NULL,
  organization_id     BIGINT UNSIGNED NOT NULL,
  asset_type          ENUM('GOLD','RIAL') NOT NULL,

  -- مثبت = بستانکار، منفی = بدهکار
  -- GOLD: میلی‌گرم خالص | RIAL: ریال
  amount              BIGINT NOT NULL,

  entry_type          VARCHAR(50) NOT NULL,
  direction           ENUM('CREDIT','DEBIT') NOT NULL,

  reference_type      VARCHAR(50) NOT NULL,
  reference_id        BIGINT UNSIGNED NOT NULL,
  transaction_group   CHAR(36) NOT NULL,

  balance_after       BIGINT NOT NULL,

  description         VARCHAR(500) NULL,
  metadata            JSON NULL,

  created_by_user_id  BIGINT UNSIGNED NULL,
  created_at          TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  prev_hash           CHAR(64) NULL,
  row_hash            CHAR(64) NOT NULL,

  PRIMARY KEY (id, created_at),
  KEY idx_account_time (account_id, created_at),
  KEY idx_org_time (organization_id, created_at),
  KEY idx_reference (reference_type, reference_id),
  KEY idx_group (transaction_group),
  KEY idx_type_time (entry_type, created_at),
  CONSTRAINT chk_amount_not_zero CHECK (amount <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
PARTITION BY RANGE (UNIX_TIMESTAMP(created_at)) (
  PARTITION p2026_01 VALUES LESS THAN (UNIX_TIMESTAMP('2026-02-01 00:00:00')),
  PARTITION p2026_02 VALUES LESS THAN (UNIX_TIMESTAMP('2026-03-01 00:00:00')),
  PARTITION p2026_03 VALUES LESS THAN (UNIX_TIMESTAMP('2026-04-01 00:00:00')),
  PARTITION pmax     VALUES LESS THAN MAXVALUE
);
```

<div dir="rtl">

</div>

```sql
CREATE TABLE ledger_balances (
  account_id      BIGINT UNSIGNED NOT NULL,
  balance         BIGINT NOT NULL DEFAULT 0,
  last_entry_id   BIGINT UNSIGNED NULL,
  entry_count     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  version         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at      TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
                    ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (account_id),
  CONSTRAINT fk_balance_account FOREIGN KEY (account_id)
    REFERENCES ledger_accounts (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

<div dir="rtl">

> ⚠️ محدودیت `balance >= 0` نمی‌تواند به‌صورت `CHECK` ساده اعمال شود
> چون به `bucket` حساب بستگی دارد و MySQL در `CHECK` امکان subquery ندارد.
>
> **راهکار:** trigger + اعمال در اپلیکیشن:

</div>

```sql
DELIMITER $$

CREATE TRIGGER trg_balance_non_negative
BEFORE UPDATE ON ledger_balances
FOR EACH ROW
BEGIN
  DECLARE v_allows_negative BOOLEAN;

  SELECT allows_negative INTO v_allows_negative
  FROM ledger_accounts WHERE id = NEW.account_id;

  IF v_allows_negative = FALSE AND NEW.balance < 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Ledger balance cannot be negative';
  END IF;
END$$

DELIMITER ;
```

<div dir="rtl">

</div>

```sql
CREATE TABLE ledger_reversals (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  original_entry_id     BIGINT UNSIGNED NOT NULL,
  reversal_entry_id     BIGINT UNSIGNED NOT NULL,
  reason                VARCHAR(500) NOT NULL,
  requested_by_user_id  BIGINT UNSIGNED NOT NULL,
  approved_by_user_id   BIGINT UNSIGNED NOT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_original (original_entry_id),
  UNIQUE KEY uq_reversal (reversal_entry_id),
  CONSTRAINT chk_different_approver
    CHECK (requested_by_user_id <> approved_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

<div dir="rtl">

---

## ۲.۳ Custody

</div>

```sql
CREATE TABLE gold_lots (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lot_code              VARCHAR(20) NOT NULL,        -- GL-00001287
  metal_type            ENUM('GOLD','SILVER','PLATINUM') NOT NULL DEFAULT 'GOLD',

  -- وزن و عیار
  gross_weight_mg       BIGINT UNSIGNED NOT NULL,
  purity_x10            SMALLINT UNSIGNED NOT NULL,  -- 0..10000
  fine_weight_mg        BIGINT UNSIGNED NOT NULL,
  purity_source         ENUM('ASSAYED','DECLARED','ESTIMATED') NOT NULL,

  -- هویت فیزیکی
  serial_number         VARCHAR(50) NULL,
  hallmark_code         VARCHAR(50) NULL,
  shape                 ENUM('BAR','GRAIN','SCRAP','COIN','OTHER') NOT NULL,
  qr_token              CHAR(64) NOT NULL,

  -- مبدأ
  origin_type           ENUM('MELT','IMPORT','MEMBER_DEPOSIT','SPLIT',
                             'MERGE','REASSAY') NOT NULL,
  refiner_id            BIGINT UNSIGNED NULL,
  refined_at            TIMESTAMP NULL,
  current_assay_id      BIGINT UNSIGNED NULL,
  generation            SMALLINT UNSIGNED NOT NULL DEFAULT 1,

  -- مالکیت (مستقل از نگهداری)
  owner_organization_id BIGINT UNSIGNED NOT NULL,

  -- نگهداری
  custodian_type        ENUM('VAULT','ORGANIZATION','LAB','IN_TRANSIT',
                             'THIRD_PARTY') NOT NULL,
  custodian_id          BIGINT UNSIGNED NOT NULL,
  vault_box_id          BIGINT UNSIGNED NULL,
  physical_location     VARCHAR(50) NULL,            -- V01-S03-F02-B14

  -- وضعیت
  status                ENUM('UNDER_ASSAY','AVAILABLE','RESERVED',
                             'IN_SETTLEMENT','IN_TRANSIT','ON_HOLD',
                             'WITHDRAWN','CONSUMED') NOT NULL,
  hold_reason           VARCHAR(500) NULL,

  version               BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_lot_code (lot_code),
  UNIQUE KEY uq_qr_token (qr_token),
  KEY idx_owner_status (owner_organization_id, status),
  KEY idx_allocation (owner_organization_id, status, custodian_type, purity_x10),
  KEY idx_custodian (custodian_type, custodian_id),
  KEY idx_box (vault_box_id),
  KEY idx_serial (serial_number),

  CONSTRAINT chk_purity_range CHECK (purity_x10 <= 10000),
  CONSTRAINT chk_fine_le_gross CHECK (fine_weight_mg <= gross_weight_mg),
  CONSTRAINT chk_weights_positive CHECK (gross_weight_mg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

<div dir="rtl">

</div>

```sql
CREATE TABLE assays (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assay_code          VARCHAR(20) NOT NULL,
  gold_lot_id         BIGINT UNSIGNED NOT NULL,
  certificate_no      VARCHAR(100) NOT NULL,
  laboratory_id       BIGINT UNSIGNED NOT NULL,
  method              ENUM('FIRE_ASSAY','XRF','ICP','OTHER') NOT NULL,

  gross_weight_mg     BIGINT UNSIGNED NOT NULL,
  purity_x10          SMALLINT UNSIGNED NOT NULL,
  fine_weight_mg      BIGINT UNSIGNED NOT NULL,

  assayed_at          TIMESTAMP NOT NULL,
  valid_until         TIMESTAMP NULL,
  document_id         BIGINT UNSIGNED NULL,
  qr_token            CHAR(64) NOT NULL,

  status              ENUM('VALID','SUPERSEDED','DISPUTED','REVOKED') NOT NULL,
  superseded_by_id    BIGINT UNSIGNED NULL,
  verified_by_lab_at  TIMESTAMP NULL,
  recorded_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_assay_code (assay_code),
  UNIQUE KEY uq_qr_token (qr_token),
  UNIQUE KEY uq_lab_certificate (laboratory_id, certificate_no),
  KEY idx_lot (gold_lot_id, status),
  KEY idx_lab (laboratory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

<div dir="rtl">

</div>

```sql
CREATE TABLE lot_lineage (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_lot_id   BIGINT UNSIGNED NOT NULL,
  child_lot_id    BIGINT UNSIGNED NOT NULL,
  operation       ENUM('SPLIT','MERGE','MELT','REASSAY') NOT NULL,
  operation_id    BIGINT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_parent_child (parent_lot_id, child_lot_id),
  KEY idx_child (child_lot_id),
  CONSTRAINT chk_no_self_parent CHECK (parent_lot_id <> child_lot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

<div dir="rtl">

---

## ۲.۴ Trading

</div>

```sql
CREATE TABLE instruments (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code              VARCHAR(30) NOT NULL,       -- GOLD-995-T0
  name              VARCHAR(100) NOT NULL,
  metal_type        ENUM('GOLD','SILVER','PLATINUM') NOT NULL DEFAULT 'GOLD',
  min_purity_x10    SMALLINT UNSIGNED NOT NULL,
  quote_unit        ENUM('GRAM_FINE','GRAM_GROSS','MESGHAL') NOT NULL,
  settlement_type   ENUM('T0','T1','T2','T3') NOT NULL,
  tick_size_rial    BIGINT UNSIGNED NOT NULL,
  lot_size_mg       BIGINT UNSIGNED NOT NULL,
  min_order_mg      BIGINT UNSIGNED NOT NULL,
  max_order_mg      BIGINT UNSIGNED NOT NULL,
  max_price_deviation_bps INT UNSIGNED NOT NULL DEFAULT 1000,
  status            ENUM('ACTIVE','PAUSED','CLOSED') NOT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

<div dir="rtl">

</div>

```sql
CREATE TABLE orders (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_code            VARCHAR(20) NOT NULL,
  instrument_id         BIGINT UNSIGNED NOT NULL,
  organization_id       BIGINT UNSIGNED NOT NULL,
  created_by_user_id    BIGINT UNSIGNED NOT NULL,
  representative_id     BIGINT UNSIGNED NULL,

  side                  ENUM('BUY','SELL') NOT NULL,
  order_type            ENUM('MARKET','LIMIT') NOT NULL,
  time_in_force         ENUM('DAY','GTC','GTD','IOC','FOK') NOT NULL DEFAULT 'DAY',

  quantity_mg           BIGINT UNSIGNED NOT NULL,
  filled_mg             BIGINT UNSIGNED NOT NULL DEFAULT 0,
  price_rial            BIGINT UNSIGNED NULL,
  max_slippage_bps      INT UNSIGNED NULL,

  reservation_entry_id  BIGINT UNSIGNED NULL,

  status                ENUM('PENDING','OPEN','PARTIALLY_FILLED','FILLED',
                             'CANCELLED','REJECTED','EXPIRED') NOT NULL,
  reject_reason         VARCHAR(500) NULL,

  placed_at             TIMESTAMP(3) NOT NULL,
  expires_at            TIMESTAMP NULL,
  closed_at             TIMESTAMP NULL,

  metadata              JSON NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_order_code (order_code),
  KEY idx_orderbook (instrument_id, side, status, price_rial, placed_at),
  KEY idx_org_status (organization_id, status),
  KEY idx_expires (status, expires_at),

  CONSTRAINT chk_filled_le_quantity CHECK (filled_mg <= quantity_mg),
  CONSTRAINT chk_limit_has_price
    CHECK (order_type <> 'LIMIT' OR price_rial IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

<div dir="rtl">

</div>

```sql
CREATE TABLE trades (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  trade_code              VARCHAR(20) NOT NULL,
  instrument_id           BIGINT UNSIGNED NOT NULL,
  trade_source            ENUM('ORDER_BOOK','OTC','RFQ') NOT NULL,

  buy_order_id            BIGINT UNSIGNED NULL,
  sell_order_id           BIGINT UNSIGNED NULL,
  otc_offer_id            BIGINT UNSIGNED NULL,
  rfq_quote_id            BIGINT UNSIGNED NULL,

  buyer_organization_id   BIGINT UNSIGNED NOT NULL,
  seller_organization_id  BIGINT UNSIGNED NOT NULL,
  maker_side              ENUM('BUY','SELL') NULL,

  quantity_fine_mg        BIGINT UNSIGNED NOT NULL,
  price_per_gram_rial     BIGINT UNSIGNED NOT NULL,
  gross_amount_rial       BIGINT UNSIGNED NOT NULL,

  buyer_fee_rial          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  seller_fee_rial         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tax_rial                BIGINT UNSIGNED NOT NULL DEFAULT 0,
  buyer_net_rial          BIGINT UNSIGNED NOT NULL,
  seller_net_rial         BIGINT UNSIGNED NOT NULL,

  settlement_type         ENUM('INSTANT','T0','T1','T2','T3','ON_ACCOUNT') NOT NULL,
  delivery_type           ENUM('VAULT_TRANSFER','PHYSICAL_HANDOVER',
                               'CUSTODY_CHANGE','NETTED') NOT NULL,
  settlement_deadline     TIMESTAMP NOT NULL,
  settlement_id           BIGINT UNSIGNED NULL,

  status                  ENUM('EXECUTED','SETTLING','SETTLED',
                               'DISPUTED','REVERSED') NOT NULL,

  executed_at             TIMESTAMP(3) NOT NULL,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id, executed_at),
  UNIQUE KEY uq_trade_code (trade_code, executed_at),
  KEY idx_buyer (buyer_organization_id, executed_at),
  KEY idx_seller (seller_organization_id, executed_at),
  KEY idx_instrument_time (instrument_id, executed_at),
  KEY idx_source (trade_source, executed_at),

  CONSTRAINT chk_no_self_trade
    CHECK (buyer_organization_id <> seller_organization_id),
  CONSTRAINT chk_amounts_balance
    CHECK (buyer_net_rial = gross_amount_rial + buyer_fee_rial + tax_rial)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
PARTITION BY RANGE (UNIX_TIMESTAMP(executed_at)) (
  PARTITION p2026_01 VALUES LESS THAN (UNIX_TIMESTAMP('2026-02-01 00:00:00')),
  PARTITION pmax     VALUES LESS THAN MAXVALUE
);
```

<div dir="rtl">

---

## ۲.۵ Settlement

</div>

```sql
CREATE TABLE settlements (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  settlement_code         VARCHAR(20) NOT NULL,
  trade_id                BIGINT UNSIGNED NOT NULL,
  settlement_type         ENUM('INSTANT','T0','T1','T2','T3','ON_ACCOUNT') NOT NULL,

  gold_deliverer_org_id   BIGINT UNSIGNED NOT NULL,
  gold_receiver_org_id    BIGINT UNSIGNED NOT NULL,
  cash_payer_org_id       BIGINT UNSIGNED NOT NULL,
  cash_receiver_org_id    BIGINT UNSIGNED NOT NULL,

  fine_weight_mg          BIGINT UNSIGNED NOT NULL,
  cash_amount_rial        BIGINT UNSIGNED NOT NULL,
  buyer_fee_rial          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  seller_fee_rial         BIGINT UNSIGNED NOT NULL DEFAULT 0,

  delivery_method         ENUM('VAULT_TRANSFER','PHYSICAL_HANDOVER',
                               'CUSTODY_CHANGE','NETTED') NOT NULL,
  allocated_lot_ids       JSON NULL,
  gold_transferred_at     TIMESTAMP NULL,
  gold_confirmed_by_user_id BIGINT UNSIGNED NULL,

  payment_method          ENUM('BANK_TRANSFER','INTERNAL','NETTED') NOT NULL,
  payment_reference       VARCHAR(100) NULL,
  bank_transaction_id     VARCHAR(100) NULL,
  payment_declared_at     TIMESTAMP NULL,
  payment_confirmed_at    TIMESTAMP NULL,
  auto_matched_at         TIMESTAMP NULL,

  gold_reservation_entry_id BIGINT UNSIGNED NULL,
  cash_reservation_entry_id BIGINT UNSIGNED NULL,

  deadline_at             TIMESTAMP NOT NULL,
  overdue_since           TIMESTAMP NULL,
  penalty_rial            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  completed_at            TIMESTAMP NULL,

  status                  ENUM('CREATED','ASSETS_LOCKED','PAYMENT_PENDING',
                               'PAYMENT_DECLARED','PAYMENT_CONFIRMED',
                               'GOLD_TRANSFERRING','SETTLED','COMPLETED',
                               'OVERDUE','DEFAULTED','CANCELLED','DISPUTED',
                               'REVERSED','NETTING_QUEUE') NOT NULL,

  netting_batch_id        BIGINT UNSIGNED NULL,
  dispute_id              BIGINT UNSIGNED NULL,
  parent_settlement_id    BIGINT UNSIGNED NULL,   -- برای تسویه جزئی

  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_settlement_code (settlement_code),
  KEY idx_payer_pending (cash_payer_org_id, status, deadline_at),
  KEY idx_receiver_pending (cash_receiver_org_id, status, deadline_at),
  KEY idx_deliverer (gold_deliverer_org_id, status),
  KEY idx_deadline (status, deadline_at),
  KEY idx_trade (trade_id),
  KEY idx_netting (netting_batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

<div dir="rtl">

</div>

```sql
CREATE TABLE settlement_events (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  settlement_id   BIGINT UNSIGNED NOT NULL,
  from_status     VARCHAR(30) NULL,
  to_status       VARCHAR(30) NOT NULL,
  actor_type      ENUM('USER','SYSTEM','PLATFORM_STAFF') NOT NULL,
  actor_user_id   BIGINT UNSIGNED NULL,
  reason          VARCHAR(500) NULL,
  metadata        JSON NULL,
  occurred_at     TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_settlement_time (settlement_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

<div dir="rtl">

---

## ۲.۶ Shared

</div>

```sql
CREATE TABLE audit_logs (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  occurred_at      TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  actor_type       ENUM('user','system','platform_staff','api_client') NOT NULL,
  actor_id         BIGINT UNSIGNED NULL,
  organization_id  BIGINT UNSIGNED NULL,
  action           VARCHAR(100) NOT NULL,
  subject_type     VARCHAR(100) NULL,
  subject_id       BIGINT UNSIGNED NULL,
  before_state     JSON NULL,
  after_state      JSON NULL,
  ip_address       VARBINARY(16) NULL,
  user_agent       VARCHAR(500) NULL,
  session_id       CHAR(36) NULL,
  request_id       CHAR(36) NULL,
  result           ENUM('success','failure','denied') NOT NULL,
  failure_reason   VARCHAR(500) NULL,
  prev_hash        CHAR(64) NULL,
  row_hash         CHAR(64) NOT NULL,
  PRIMARY KEY (id, occurred_at),
  KEY idx_org_time (organization_id, occurred_at),
  KEY idx_actor_time (actor_id, occurred_at),
  KEY idx_subject (subject_type, subject_id),
  KEY idx_action_time (action, occurred_at),
  KEY idx_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
PARTITION BY RANGE (UNIX_TIMESTAMP(occurred_at)) (
  PARTITION p2026_01 VALUES LESS THAN (UNIX_TIMESTAMP('2026-02-01 00:00:00')),
  PARTITION pmax     VALUES LESS THAN MAXVALUE
);
```

<div dir="rtl">

</div>

```sql
CREATE TABLE idempotency_keys (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`           CHAR(36) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  endpoint        VARCHAR(191) NOT NULL,
  request_hash    CHAR(64) NOT NULL,
  status          ENUM('processing','completed','failed') NOT NULL,
  response_code   SMALLINT UNSIGNED NULL,
  response_body   JSON NULL,
  locked_at       TIMESTAMP NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at      TIMESTAMP NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_key_org (`key`, organization_id),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

<div dir="rtl">

</div>

```sql
CREATE TABLE business_calendar (
  calendar_date   DATE NOT NULL,
  jalali_date     VARCHAR(10) NOT NULL,       -- 1404-08-05
  is_working_day  BOOLEAN NOT NULL,
  is_holiday      BOOLEAN NOT NULL DEFAULT FALSE,
  holiday_name    VARCHAR(191) NULL,
  market_opens_at TIME NULL,
  market_closes_at TIME NULL,
  PRIMARY KEY (calendar_date),
  KEY idx_working (is_working_day, calendar_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

<div dir="rtl">

</div>

```sql
CREATE TABLE system_settings (
  `key`           VARCHAR(100) NOT NULL,
  value           JSON NOT NULL,
  value_type      ENUM('string','int','bool','json','decimal') NOT NULL,
  description     VARCHAR(500) NULL,
  is_sensitive    BOOLEAN NOT NULL DEFAULT FALSE,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

<div dir="rtl">

---

## ۲.۷ داده اولیه (Seed)

</div>

```sql
-- حساب‌های سیستمی (organization_id = 0)
INSERT INTO ledger_accounts
  (organization_id, asset_type, metal_type, bucket, system_account_code, allows_negative)
VALUES
  (0, 'RIAL', NULL,   'AVAILABLE', 'FEE_INCOME',          TRUE),
  (0, 'RIAL', NULL,   'AVAILABLE', 'ROUNDING_DIFFERENCE', TRUE),
  (0, 'GOLD', 'GOLD', 'AVAILABLE', 'ROUNDING_DIFFERENCE', TRUE),
  (0, 'GOLD', 'GOLD', 'AVAILABLE', 'PROCESSING_LOSS',     TRUE),
  (0, 'GOLD', 'GOLD', 'AVAILABLE', 'ASSAY_VARIANCE',      TRUE),
  (0, 'GOLD', 'GOLD', 'AVAILABLE', 'EXTERNAL_GOLD_IN',    TRUE),
  (0, 'GOLD', 'GOLD', 'AVAILABLE', 'EXTERNAL_GOLD_OUT',   TRUE),
  (0, 'RIAL', NULL,   'AVAILABLE', 'EXTERNAL_CASH_IN',    TRUE),
  (0, 'RIAL', NULL,   'AVAILABLE', 'EXTERNAL_CASH_OUT',   TRUE),
  (0, 'GOLD', 'GOLD', 'AVAILABLE', 'SUSPENSE',            TRUE),
  (0, 'RIAL', NULL,   'AVAILABLE', 'SUSPENSE',            TRUE),
  (0, 'GOLD', 'GOLD', 'AVAILABLE', 'CLEARING',            TRUE),
  (0, 'RIAL', NULL,   'AVAILABLE', 'CLEARING',            TRUE);

-- ابزارهای معاملاتی
INSERT INTO instruments
  (code, name, min_purity_x10, quote_unit, settlement_type,
   tick_size_rial, lot_size_mg, min_order_mg, max_order_mg, status)
VALUES
  ('GOLD-995-T0', 'آب‌شده ۹۹۵ نقدی', 9950, 'GRAM_FINE', 'T0',
   10000, 1000, 50000, 50000000, 'ACTIVE'),
  ('GOLD-995-T1', 'آب‌شده ۹۹۵ فردایی', 9950, 'GRAM_FINE', 'T1',
   10000, 1000, 50000, 50000000, 'ACTIVE'),
  ('GOLD-750-T0', 'آب‌شده ۷۵۰ نقدی', 7500, 'GRAM_FINE', 'T0',
   10000, 1000, 100000, 50000000, 'ACTIVE');

-- تنظیمات پایه
INSERT INTO system_settings (`key`, value, value_type, description) VALUES
  ('market.open_time',              '"09:00"',  'string',  'ساعت بازگشایی'),
  ('market.close_time',             '"17:30"',  'string',  'ساعت بسته شدن'),
  ('market.pre_open_time',          '"08:45"',  'string',  'شروع پیش‌گشایش'),
  ('settlement.default_deadline_hours', '8',    'int',     'مهلت پیش‌فرض تسویه'),
  ('settlement.overdue_grace_minutes',  '0',    'int',     'مهلت ارفاقی'),
  ('settlement.penalty_daily_x100k',    '50',   'int',     'جریمه روزانه ۰.۰۵٪'),
  ('fee.default_taker_x100k',       '150',      'int',     'کارمزد taker ۰.۱۵٪'),
  ('fee.default_maker_x100k',       '100',      'int',     'کارمزد maker ۰.۱۰٪'),
  ('risk.new_member_max_order_mg',  '2000000',  'int',     'سقف سفارش عضو جدید ۲ کیلو'),
  ('risk.new_member_daily_mg',      '10000000', 'int',     'سقف روزانه ۱۰ کیلو'),
  ('pricing.max_source_staleness_s','300',      'int',     'حداکثر کهنگی قیمت'),
  ('pricing.circuit_breaker_bps',   '300',      'int',     'آستانه توقف ۳٪'),
  ('dispute.reply_deadline_hours',  '24',       'int',     'مهلت پاسخ اختلاف'),
  ('dispute.negotiation_hours',     '48',       'int',     'مهلت مذاکره');
```

<div dir="rtl">

---

## ۲.۸ نکات مهاجرت (Migration)

</div>

```
۱) هر migration باید برگشت‌پذیر (down) داشته باشد
   ► به‌جز migrationهایی که داده حذف می‌کنند (که ممنوع است)

۲) migration روی جدول بزرگ باید با ابزار آنلاین انجام شود
   ► pt-online-schema-change یا gh-ost
   ► ALTER مستقیم روی ledger_entries می‌تواند ساعت‌ها قفل کند

۳) ترتیب اجرا مهم است:
   ۱. جداول بدون وابستگی (organizations, instruments, ...)
   ۲. جداول وابسته (users, ledger_accounts, ...)
   ۳. جداول تراکنشی (orders, trades, ...)
   ۴. ایندکس‌های ثانویه (پس از پر شدن داده اولیه سریع‌تر است)
   ۵. Trigger و View

۴) افزودن ستون جدید:
   ► همیشه NULL یا با DEFAULT
   ► هرگز NOT NULL بدون DEFAULT روی جدول پرداده

۵) تغییر ENUM:
   ► افزودن مقدار جدید در انتها ► ارزان
   ► حذف یا تغییر ترتیب ► گران و خطرناک
   ► برای فهرست‌های پویا از جدول lookup استفاده کنید

۶) هر migration در staging با کپی داده تولید تست شود
```

<div dir="rtl">

---

## ۲.۹ برآورد اندازه

با فرض ۲۵۰ عضو فعال و ۱۰٬۰۰۰ معامله در روز:

| جدول | رشد روزانه | ۱ سال | نکته |
|---|---|---|---|
| `ledger_entries` | ~۸۰٬۰۰۰ سطر | ~۲۹M | ۸ entry به ازای هر معامله |
| `audit_logs` | ~۲۰۰٬۰۰۰ سطر | ~۷۳M | حجیم‌ترین |
| `trades` | ۱۰٬۰۰۰ | ~۳.۶M | |
| `orders` | ~۲۵٬۰۰۰ | ~۹M | شامل لغوشده‌ها |
| `settlements` | ۱۰٬۰۰۰ | ~۳.۶M | |
| `price_ticks` | ~۸۶٬۴۰۰ | ~۳۱M | با نگهداری ۹۰ روزه: ~۸M |
| `notifications` | ~۵۰٬۰۰۰ | ~۱۸M | |
| `gold_lots` | ~۵۰۰ | ~۱۸۰K | |

**برآورد کل ۱ سال: حدود ۱۵۰–۲۵۰ گیگابایت** (با ایندکس).

راهکار: پارتیشن‌بندی + بایگانی سرد + جدول‌های تجمیعی.

</div>
