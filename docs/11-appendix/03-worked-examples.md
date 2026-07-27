<div dir="rtl">

# ۳. مثال‌های کامل انتها به انتها

این سند سناریوهای واقعی را با تمام جزئیات (رکوردهای دیتابیس، ثبت‌های
دفتر، رویدادها) نشان می‌دهد. مبنای تست‌های یکپارچگی.

---

## مثال ۱ — معامله ساده در Order Book

### وضعیت اولیه

</div>

```
سازمان ۱۸۴ (فروشنده — طلافروشی کریمی)
  GOLD/AVAILABLE      : 1,000,000 mg  (۱ کیلوگرم خالص)
  GOLD/RESERVED       :         0
  RIAL/AVAILABLE      : 500,000,000

سازمان ۲۹۱ (خریدار — بنکداری پارس)
  GOLD/AVAILABLE      :         0
  RIAL/AVAILABLE      : 25,000,000,000

lot موجود:
  GL-00001287 | gross 251,256 mg | purity 9950 | fine 250,000 mg
              | owner 184 | custodian VAULT/1 | AVAILABLE
```

<div dir="rtl">

### گام ۱ — فروشنده سفارش می‌گذارد

</div>

```http
POST /api/v1/orders
Idempotency-Key: 11111111-1111-4111-8111-111111111111

{
  "instrument": "GOLD-995-T0",
  "side": "SELL",
  "type": "LIMIT",
  "quantity_mg": 250000,
  "price_rial": 78480000
}
```

<div dir="rtl">

**ثبت‌های دفتر (transaction_group = `g1`):**

</div>

```
account                     amount        type      balance_after
────────────────────────────────────────────────────────────────
184/GOLD/AVAILABLE        -250,000     RESERVE          750,000
184/GOLD/RESERVED         +250,000     RESERVE          250,000
────────────────────────────────────────────────────────────────
Σ = 0  ✅
```

<div dir="rtl">

**رکورد سفارش:**

</div>

```
orders
  id: 44101
  order_code: ORD-00044101
  organization_id: 184
  side: SELL
  type: LIMIT
  quantity_mg: 250000
  filled_mg: 0
  price_rial: 78480000
  reservation_entry_id: <id ثبت RESERVED>
  status: OPEN
  placed_at: 2026-07-27T09:10:00.000Z
```

<div dir="rtl">

**رویداد:** `OrderPlaced(orderId: 44101, ...)`

### گام ۲ — خریدار سفارش می‌گذارد و تطبیق رخ می‌دهد

</div>

```http
POST /api/v1/orders
Idempotency-Key: 22222222-2222-4222-8222-222222222222

{
  "instrument": "GOLD-995-T0",
  "side": "BUY",
  "type": "LIMIT",
  "quantity_mg": 250000,
  "price_rial": 78500000
}
```

<div dir="rtl">

**محاسبه رزرو خریدار (F5, F8, F9):**

</div>

```
gross    = floor(250,000 × 78,500,000 / 1,000) = 19,625,000,000
fee      = ceil(19,625,000,000 × 150 / 100,000) = 29,437,500
required = 19,654,437,500 ریال

⚠️ رزرو با قیمت سفارش خودش (78,500,000) انجام می‌شود،
   چون هنوز نمی‌داند با چه قیمتی اجرا خواهد شد.
```

<div dir="rtl">

**ثبت‌های دفتر (`g2`):**

</div>

```
account                        amount           type      balance_after
──────────────────────────────────────────────────────────────────────
291/RIAL/AVAILABLE      -19,654,437,500      RESERVE       5,345,562,500
291/RIAL/RESERVED       +19,654,437,500      RESERVE      19,654,437,500
──────────────────────────────────────────────────────────────────────
Σ = 0  ✅
```

<div dir="rtl">

**تطبیق:**

</div>

```
سفارش ورودی: BUY 250,000 @ 78,500,000  (taker)
یافت شد:     SELL 250,000 @ 78,480,000  (maker، قدیمی‌تر)

شرط: 78,480,000 ≤ 78,500,000  ✅
قیمت اجرا = قیمت maker = 78,480,000
```

<div dir="rtl">

**محاسبه نهایی معامله:**

</div>

```
fine        = 250,000 mg
price       = 78,480,000
gross       = floor(250,000 × 78,480,000 / 1,000) = 19,620,000,000
buyer_fee   = ceil(19,620,000,000 × 150 / 100,000) = 29,430,000  (taker)
seller_fee  = ceil(19,620,000,000 × 100 / 100,000) = 19,620,000  (maker)
buyer_net   = 19,620,000,000 + 29,430,000 = 19,649,430,000
seller_net  = 19,620,000,000 − 19,620,000 = 19,600,380,000
```

<div dir="rtl">

**آزادسازی مازاد رزرو خریدار (`g3`):**

</div>

```
رزرو شده : 19,654,437,500
نیاز واقعی: 19,649,430,000
مازاد    :      5,007,500

account                     amount        type      balance_after
────────────────────────────────────────────────────────────────
291/RIAL/RESERVED        -5,007,500     RELEASE     19,649,430,000
291/RIAL/AVAILABLE       +5,007,500     RELEASE      5,350,570,000
────────────────────────────────────────────────────────────────
Σ = 0  ✅
```

<div dir="rtl">

**رکورد معامله:**

</div>

```
trades
  id: 88231
  trade_code: TRD-00088231
  trade_source: ORDER_BOOK
  buy_order_id: 44120
  sell_order_id: 44101
  buyer_organization_id: 291
  seller_organization_id: 184
  maker_side: SELL
  quantity_fine_mg: 250000
  price_per_gram_rial: 78480000
  gross_amount_rial: 19620000000
  buyer_fee_rial: 29430000
  seller_fee_rial: 19620000
  buyer_net_rial: 19649430000
  seller_net_rial: 19600380000
  settlement_type: T0
  settlement_deadline: 2026-07-27T17:00:00Z
  status: EXECUTED
  executed_at: 2026-07-27T09:15:33.480Z
```

<div dir="rtl">

**رویداد:** `TradeExecuted(...)`

### گام ۳ — ایجاد تسویه و قفل دارایی

</div>

```
settlements
  id: 88231
  settlement_code: STL-00088231
  trade_id: 88231
  gold_deliverer_org_id: 184
  gold_receiver_org_id: 291
  cash_payer_org_id: 291
  cash_receiver_org_id: 184
  fine_weight_mg: 250000
  cash_amount_rial: 19620000000
  buyer_fee_rial: 29430000
  seller_fee_rial: 19620000
  delivery_method: CUSTODY_CHANGE
  allocated_lot_ids: [1287]
  deadline_at: 2026-07-27T17:00:00Z
  status: ASSETS_LOCKED
```

<div dir="rtl">

**انتقال به IN_SETTLEMENT (`g4`):**

</div>

```
account                        amount              type          balance_after
─────────────────────────────────────────────────────────────────────────────
184/GOLD/RESERVED           -250,000       SETTLEMENT_LOCK                  0
184/GOLD/IN_SETTLEMENT      +250,000       SETTLEMENT_LOCK            250,000
─────────────────────────────────────────────────────────────────────────────
Σ = 0  ✅

291/RIAL/RESERVED    -19,649,430,000       SETTLEMENT_LOCK                  0
291/RIAL/IN_SETTLEMENT +19,649,430,000     SETTLEMENT_LOCK     19,649,430,000
─────────────────────────────────────────────────────────────────────────────
Σ = 0  ✅
```

<div dir="rtl">

**وضعیت lot:** `AVAILABLE → IN_SETTLEMENT`

### گام ۴ — خریدار پرداخت را اعلام می‌کند

</div>

```http
POST /api/v1/settlements/88231/declare-payment
Idempotency-Key: 33333333-3333-4333-8333-333333333333

{
  "payment_reference": "987654321",
  "amount_rial": 19649430000,
  "paid_at": "2026-07-27T10:30:00Z"
}
```

<div dir="rtl">

</div>

```
settlements.status: ASSETS_LOCKED → PAYMENT_PENDING → PAYMENT_DECLARED
settlements.payment_reference: "987654321"
settlements.payment_declared_at: 2026-07-27T10:30:15Z

بدون ثبت در دفتر (هنوز پول واقعاً منتقل نشده)
```

<div dir="rtl">

### گام ۵ — فروشنده دریافت را تأیید می‌کند

</div>

```http
POST /api/v1/settlements/88231/confirm-payment
Idempotency-Key: 44444444-4444-4444-8444-444444444444
```

<div dir="rtl">

**انتقال ریال (`g5`):**

</div>

```
account                        amount              type          balance_after
─────────────────────────────────────────────────────────────────────────────
291/RIAL/IN_SETTLEMENT  -19,649,430,000    TRADE_BUY_CASH                   0
184/RIAL/AVAILABLE      +19,600,380,000    TRADE_SELL_CASH     20,100,380,000
SYSTEM/FEE_INCOME           +29,430,000    FEE_INCOME              29,430,000
SYSTEM/FEE_INCOME           +19,620,000    FEE_INCOME              49,050,000
─────────────────────────────────────────────────────────────────────────────
Σ = -19,649,430,000 + 19,600,380,000 + 29,430,000 + 19,620,000 = 0  ✅
```

<div dir="rtl">

**وضعیت:** `PAYMENT_DECLARED → PAYMENT_CONFIRMED → GOLD_TRANSFERRING`

### گام ۶ — انتقال طلا

</div>

```
تخصیص lot: GL-00001287 (fine 250,000 mg — دقیقاً برابر)
► نیازی به Split نیست

تغییر lot:
  owner_organization_id: 184 → 291
  custodian_type: VAULT (بدون تغییر)
  custodian_id: 1 (بدون تغییر)
  physical_location: V01-S03-B14 (بدون تغییر)
  status: IN_SETTLEMENT → AVAILABLE

⚠️ طلا هیچ جا جابه‌جا نشد — فقط مالک عوض شد.
```

<div dir="rtl">

**ثبت‌های دفتر (`g6`):**

</div>

```
account                        amount              type          balance_after
─────────────────────────────────────────────────────────────────────────────
184/GOLD/IN_SETTLEMENT      -250,000       TRADE_SELL_GOLD                  0
291/GOLD/AVAILABLE          +250,000       TRADE_BUY_GOLD             250,000
─────────────────────────────────────────────────────────────────────────────
Σ = 0  ✅
```

<div dir="rtl">

**وضعیت:** `GOLD_TRANSFERRING → SETTLED`

### وضعیت نهایی

</div>

```
سازمان ۱۸۴ (فروشنده)
  GOLD/AVAILABLE      :   750,000 mg   (بود 1,000,000)
  GOLD/RESERVED       :         0
  GOLD/IN_SETTLEMENT  :         0
  RIAL/AVAILABLE      : 20,100,380,000  (بود 500,000,000)

سازمان ۲۹۱ (خریدار)
  GOLD/AVAILABLE      :   250,000 mg   (بود 0)
  RIAL/AVAILABLE      : 5,350,570,000  (بود 25,000,000,000)

SYSTEM/FEE_INCOME     :    49,050,000

lot GL-00001287:
  owner: 291 (بود 184)
  custodian: VAULT/1 (بدون تغییر)
  status: AVAILABLE
```

<div dir="rtl">

### بررسی بقای جرم

</div>

```
طلا:
  قبل: 184 دارد 1,000,000 mg
  بعد: 184 دارد 750,000 + 291 دارد 250,000 = 1,000,000 mg  ✅

ریال:
  قبل: 500,000,000 + 25,000,000,000 = 25,500,000,000
  بعد: 20,100,380,000 + 5,350,570,000 + 49,050,000 = 25,500,000,000  ✅
```

<div dir="rtl">

**مجموع ۱۲ ثبت دفتر در ۶ گروه تراکنش** — همه با Σ = ۰.

---

## مثال ۲ — تسویه با Split lot

### شرایط

</div>

```
lot GL-00001512: gross 502,513 mg | purity 9950 | fine 500,000 mg | owner 184
معامله: فروش 250,000 mg خالص به 291

► lot بزرگ‌تر از مقدار معامله است ► نیاز به Split
```

<div dir="rtl">

### عملیات Split

</div>

```
محاسبه (F2):
  برای 250,000 mg خالص با عیار 9950:
  gross_needed = ceil(250,000 × 10,000 / 9,950) = 251,257 mg

  باقیمانده:
  gross_rest = 502,513 − 251,257 = 251,256 mg
  fine_rest  = floor(251,256 × 9,950 / 10,000) = 249,999 mg

بررسی:
  250,000 + 249,999 = 499,999
  اصلی: 500,000
  اختلاف: 1 mg  ◄── ناشی از گِردکردن
```

<div dir="rtl">

**رکورد عملیات:**

</div>

```
custody_operations
  operation_type: SPLIT
  input_lot_ids: [1512]
  output_lot_ids: [1601, 1602]
  input_fine_mg: 500000
  output_fine_mg: 499999
  loss_fine_mg: 1              ◄── تفاوت گِردکردن
  reason: "Split for settlement STL-00088232"
```

<div dir="rtl">

**lotهای جدید:**

</div>

```
GL-00001601 | gross 251,257 | purity 9950 | fine 250,000 | owner 184
GL-00001602 | gross 251,256 | purity 9950 | fine 249,999 | owner 184
GL-00001512 | status: CONSUMED  (رکورد باقی می‌ماند)

lot_lineage:
  (1512 → 1601, SPLIT)
  (1512 → 1602, SPLIT)
```

<div dir="rtl">

**ثبت دفتر برای اختلاف گِردکردن (`g7`):**

</div>

```
account                     amount          type              balance_after
──────────────────────────────────────────────────────────────────────────
184/GOLD/AVAILABLE              -1     ROUNDING                    499,999
SYSTEM/ROUNDING_DIFFERENCE      +1     ROUNDING                          1
──────────────────────────────────────────────────────────────────────────
Σ = 0  ✅
```

<div dir="rtl">

> این یک میلی‌گرم به حساب سیستمی می‌رود و در گزارش‌های داخلی
> ردیابی می‌شود. با هزاران معامله، این اعداد جمع می‌شوند و باید
> به‌صورت دوره‌ای تسویه شوند (مثلاً به‌عنوان درآمد یا برگشت به اعضا).

---

## مثال ۳ — تهاتر پایان روز

### تعهدات خام

</div>

```
Settlement    از → به      وزن خالص
──────────────────────────────────────
STL-1001      A → B        100,000 mg
STL-1002      B → A         70,000 mg
STL-1003      A → C        250,000 mg
STL-1004      C → A         30,000 mg
STL-1005      B → C         40,000 mg
STL-1006      C → B         90,000 mg
──────────────────────────────────────
۶ تسویه، ۶ انتقال طلا
```

<div dir="rtl">

### محاسبه موقعیت خالص (F13)

</div>

```
A:  دریافتی: 70,000 + 30,000  = 100,000
    پرداختی: 100,000 + 250,000 = 350,000
    خالص:    100,000 − 350,000 = −250,000   (بدهکار)

B:  دریافتی: 100,000 + 90,000 = 190,000
    پرداختی: 70,000 + 40,000  = 110,000
    خالص:    190,000 − 110,000 = +80,000    (بستانکار)

C:  دریافتی: 250,000 + 40,000 = 290,000
    پرداختی: 30,000 + 90,000  = 120,000
    خالص:    290,000 − 120,000 = +170,000   (بستانکار)

بررسی: −250,000 + 80,000 + 170,000 = 0  ✅
```

<div dir="rtl">

### رکوردها

</div>

```
netting_batches
  id: 42
  batch_date: 2026-07-27
  netting_type: MULTILATERAL
  asset_type: GOLD
  status: PROPOSED
  participant_count: 3
  gross_transfer_count: 6
  net_transfer_count: 3
  gross_volume: 580000
  net_volume: 250000

netting_positions
  (42, A, gross_in=100000, gross_out=350000, net=-250000)
  (42, B, gross_in=190000, gross_out=110000, net=+80000)
  (42, C, gross_in=290000, gross_out=120000, net=+170000)

netting_settlements
  (42, 1001) (42, 1002) (42, 1003) (42, 1004) (42, 1005) (42, 1006)
```

<div dir="rtl">

### پس از پذیرش همه — اجرا (`g8`)

</div>

```
account                     amount          type              balance_after
──────────────────────────────────────────────────────────────────────────
A/GOLD/IN_SETTLEMENT      -350,000     NETTING_SETTLE                    0
A/GOLD/AVAILABLE          +100,000     NETTING_SETTLE               (+100k)
SYSTEM/CLEARING           +250,000     NETTING_SETTLE              250,000

SYSTEM/CLEARING            -80,000     NETTING_SETTLE              170,000
B/GOLD/AVAILABLE           +80,000     NETTING_SETTLE               (+80k)
B/GOLD/IN_SETTLEMENT      -110,000     NETTING_SETTLE                    0
B/GOLD/AVAILABLE          +110,000     NETTING_SETTLE              (+110k)

SYSTEM/CLEARING           -170,000     NETTING_SETTLE                    0
C/GOLD/AVAILABLE          +170,000     NETTING_SETTLE              (+170k)
C/GOLD/IN_SETTLEMENT      -120,000     NETTING_SETTLE                    0
C/GOLD/AVAILABLE          +120,000     NETTING_SETTLE              (+120k)
──────────────────────────────────────────────────────────────────────────
Σ = 0  ✅
حساب CLEARING در پایان صفر است  ✅
```

<div dir="rtl">

**همه ۶ تسویه:** `NETTING_QUEUE → SETTLED`

### بررسی معادل بودن با تسویه ناخالص

</div>

```
با تسویه ناخالص، تغییر خالص هر عضو:
  A: −100,000 + 70,000 − 250,000 + 30,000 = −250,000
  B: +100,000 − 70,000 − 40,000 + 90,000  = +80,000
  C: +250,000 − 30,000 + 40,000 − 90,000  = +170,000

با تهاتر: دقیقاً همان  ✅

اما: ۶ انتقال ► ۳ انتقال (۵۰٪ کاهش عملیات)
```

<div dir="rtl">

---

## مثال ۴ — اختلاف عیار و برگشت

### شرایط اولیه

</div>

```
معامله TRD-88231 تسویه شده:
  500,000 mg خالص با عیار اعلامی 9950
  قیمت: 78,480,000 ریال/گرم خالص
  مبلغ: 39,240,000,000 ریال

lot GL-00001287 در اختیار خریدار (291)
```

<div dir="rtl">

### ثبت اختلاف

</div>

```
خریدار ادعا می‌کند عیار واقعی 9850 است، نه 9950.

محاسبه ادعا:
  gross = ceil(500,000 × 10,000 / 9,950) = 502,513 mg

  fine با عیار واقعی = floor(502,513 × 9,850 / 10,000) = 494,975 mg

  کسری = 500,000 − 494,975 = 5,025 mg خالص
  معادل ریالی = floor(5,025 × 78,480,000 / 1,000) = 394,362,000 ریال

disputes
  id: 142
  case_number: DSP-1404-00142
  dispute_type: PURITY_MISMATCH
  trade_id: 88231
  gold_lot_id: 1287
  claimant_org_id: 291
  respondent_org_id: 184
  claim_gold_mg: 5025
  claim_rial: 394362000
  status: OPENED
```

<div dir="rtl">

**قفل مبلغ مورد اختلاف (`g9`):**

</div>

```
account                     amount          type              balance_after
──────────────────────────────────────────────────────────────────────────
184/RIAL/AVAILABLE      -394,362,000     DISPUTE_HOLD          (کاهش)
184/RIAL/IN_DISPUTE     +394,362,000     DISPUTE_HOLD       394,362,000
──────────────────────────────────────────────────────────────────────────
Σ = 0  ✅

lot GL-00001287: status → ON_HOLD
```

<div dir="rtl">

### ری‌گیری ثالث

</div>

```
lot به آزمایشگاه ۷ (TIER_1، مستقل) ارسال می‌شود
lot.custodian_type: VAULT → LAB
lot.status: ON_HOLD → UNDER_ASSAY

نتیجه: عیار 9,850  ► ادعا تأیید می‌شود

assays
  id: 4599
  gold_lot_id: 1287
  laboratory_id: 7
  purity_x10: 9850
  gross_weight_mg: 502513
  fine_weight_mg: 494975
  status: VALID

گواهی قبلی (AS-4421): status → SUPERSEDED
```

<div dir="rtl">

### رأی و اجرا

</div>

```
decision: CLAIM_UPHELD_FULL
awarded_rial: 394,362,000  (به نفع معترض)
awarded_gold_mg: 0
```

<div dir="rtl">

**اجرای رأی (`g10`):**

</div>

```
account                     amount          type              balance_after
──────────────────────────────────────────────────────────────────────────
184/RIAL/IN_DISPUTE     -394,362,000     DISPUTE_RELEASE                 0
291/RIAL/AVAILABLE      +394,362,000     DISPUTE_SETTLEMENT       (افزایش)
──────────────────────────────────────────────────────────────────────────
Σ = 0  ✅
```

<div dir="rtl">

**تعدیل وزن خالص در دفتر خریدار (`g11`):**

</div>

```
lot در دفتر با fine 500,000 ثبت شده بود، اما واقعاً 494,975 است.

account                     amount          type              balance_after
──────────────────────────────────────────────────────────────────────────
291/GOLD/AVAILABLE           -5,025     ASSAY_ADJUSTMENT       (کاهش)
SYSTEM/ASSAY_VARIANCE        +5,025     ASSAY_ADJUSTMENT          5,025
──────────────────────────────────────────────────────────────────────────
Σ = 0  ✅

lot GL-00001287:
  purity_x10: 9950 → 9850
  fine_weight_mg: 500,000 → 494,975
  current_assay_id: 4421 → 4599
  status: UNDER_ASSAY → AVAILABLE
```

<div dir="rtl">

### اثر بر Reputation

</div>

```
سازمان 184 (بازنده):
  disputes_lost += 1
  credit_score −80
  ⚠️ اگر تکرار شود، بررسی ریسک

سازمان 291 (برنده):
  بدون تغییر (جلوگیری از انگیزه ادعای بی‌اساس)

آزمایشگاه ۳ (که گواهی اولیه را داده):
  variance_count += 1
  ⚠️ اگر نرخ اختلاف بالا رود ► تنزل سطح اعتبار
```

<div dir="rtl">

---

## مثال ۵ — تلاش برای فروش دو‌باره (Race Condition)

### سناریو

</div>

```
سازمان ۱۸۴ دارد: 100,000 mg در AVAILABLE

دو درخواست همزمان:
  Thread A: فروش 80,000 mg
  Thread B: فروش 80,000 mg
```

<div dir="rtl">

### بدون قفل (❌ رفتار اشتباه)

</div>

```
t0  A: SELECT balance → 100,000
t1  B: SELECT balance → 100,000
t2  A: بررسی 100,000 ≥ 80,000 ✓
t3  B: بررسی 100,000 ≥ 80,000 ✓
t4  A: INSERT entry -80,000, UPDATE balance = 20,000
t5  B: INSERT entry -80,000, UPDATE balance = -60,000

نتیجه: مانده منفی!  ⛔
```

<div dir="rtl">

### با قفل بدبینانه (✅ رفتار درست)

</div>

```
t0  A: BEGIN
t1  A: SELECT ... FOR UPDATE → قفل گرفت، balance = 100,000
t2  B: BEGIN
t3  B: SELECT ... FOR UPDATE → منتظر قفل A
t4  A: بررسی 100,000 ≥ 80,000 ✓
t5  A: INSERT entry -80,000
t6  A: UPDATE balance = 20,000
t7  A: COMMIT → قفل آزاد شد
t8  B: قفل گرفت، balance = 20,000
t9  B: بررسی 20,000 ≥ 80,000 ✗
t10 B: throw InsufficientBalanceException
t11 B: ROLLBACK

نتیجه: یکی موفق، یکی رد شد  ✅
مانده نهایی: 20,000  ✅
```

<div dir="rtl">

### پاسخ API برای Thread B

</div>

```json
{
  "error": {
    "code": "INSUFFICIENT_GOLD",
    "message": "موجودی طلای شما برای این سفارش کافی نیست.",
    "details": {
      "required": 80000,
      "available": 20000,
      "shortfall": 60000
    }
  }
}
```

<div dir="rtl">

**HTTP 422**

---

## مثال ۶ — تسویه سررسیدگذشته و نکول

### جدول زمانی

</div>

```
۰۹:۱۵  معامله انجام شد
       deadline_at = 17:00 همان روز
       status: ASSETS_LOCKED

۰۹:۱۶  status: PAYMENT_PENDING
       اعلان: «پرداخت 19.65 میلیارد تا 17:00»

۱۵:۰۰  اعلان یادآوری: «۲ ساعت مانده»

۱۷:۰۰  ⚠️ مهلت گذشت
       status: PAYMENT_PENDING → OVERDUE
       overdue_since = 17:00
       اعلان فوری به هر دو طرف
       ثبت در پروفایل ریسک بدهکار

۱۹:۰۰  یادآوری دوم
       شروع محاسبه جریمه

۲۳:۰۰  اعلان به اپراتور
       تعلیق موقت ثبت سفارش جدید برای 291

روز بعد
۱۷:۰۰  ⛔ ۲۴ ساعت گذشت
       status: OVERDUE → DEFAULTED
       credit_score −250
       risk_level → HIGH
       ارجاع خودکار به Dispute
```

<div dir="rtl">

### محاسبه جریمه (F21)

</div>

```
amount = 19,620,000,000
daily_rate = 50 (۰.۰۵٪)
days_overdue = 1

penalty = min(
    floor(19,620,000,000 × 50 × 1 / 100,000),
    floor(19,620,000,000 × 10,000 / 100,000)
)
= min(9,810,000, 1,962,000,000)
= 9,810,000 ریال
```

<div dir="rtl">

### سناریو الف — پرداخت دیرهنگام

</div>

```
روز بعد ۲۰:۰۰  خریدار پرداخت می‌کند
       status: DEFAULTED → PAYMENT_DECLARED
       فروشنده تأیید می‌کند
       تسویه انجام می‌شود + جریمه

ثبت جریمه (g12):
account                     amount          type          balance_after
──────────────────────────────────────────────────────────────────────
291/RIAL/AVAILABLE       -9,810,000     PENALTY            (کاهش)
184/RIAL/AVAILABLE       +9,810,000     PENALTY_RECEIVED   (افزایش)
──────────────────────────────────────────────────────────────────────
Σ = 0  ✅

⚠️ آیا جریمه به فروشنده می‌رود یا به سامانه؟
   این یک تصمیم کسب‌وکار/حقوقی است.
   پیش‌فرض: به طرف زیان‌دیده (فروشنده).
```

<div dir="rtl">

### سناریو ب — نکول کامل

</div>

```
خریدار پرداخت نمی‌کند و وثیقه دارد:

۱) محاسبه: تعهد 19,620,000,000 + جریمه
۲) تأیید دوگانه SETTLEMENT_OFFICER + PLATFORM_ADMIN
۳) برداشت از وثیقه

ثبت (g13):
account                        amount              type          balance_after
──────────────────────────────────────────────────────────────────────────────
291/GOLD/COLLATERAL          -250,000     COLLATERAL_SEIZE                 0
184/GOLD/AVAILABLE           +250,000     COLLATERAL_TRANSFER       (افزایش)
──────────────────────────────────────────────────────────────────────────────
Σ = 0  ✅

۴) آزادسازی طلای قفل‌شده فروشنده
   184/GOLD/IN_SETTLEMENT −250,000
   184/GOLD/AVAILABLE     +250,000

   ⚠️ فروشنده الان ۵۰۰,۰۰۰ mg دارد:
      ۲۵۰,۰۰۰ طلای خودش که فروخته نشد
      ۲۵۰,۰۰۰ از وثیقه خریدار

۵) تعلیق سازمان 291
۶) ارجاع حقوقی
```

<div dir="rtl">

---

## مثال ۷ — سناریوی خطا: عدم تراز تراکنش

این مثال نشان می‌دهد چگونه سیستم از خودش محافظت می‌کند.

</div>

```php
// کد معیوب — یک ثبت فراموش شده
DB::transaction(function () {
    $group = TransactionGroup::generate();

    $this->writeEntry($sellerGold, -250_000, EntryType::TRADE_SELL_GOLD, $group, $ref);
    // ❌ ثبت طرف مقابل فراموش شد!

    $this->assertGroupBalances($group);   // ◄── اینجا می‌گیرد
});
```

<div dir="rtl">

**نتیجه:**

</div>

```
UnbalancedTransactionException:
  transaction_group: g99
  asset_type: GOLD
  sum: -250000  (باید 0 باشد)

► تراکنش rollback می‌شود
► هیچ ثبتی در دفتر باقی نمی‌ماند
► خطا در Sentry ثبت می‌شود
► پاسخ API: 500 INTERNAL_ERROR
```

<div dir="rtl">

**همچنین در reconcile شبانه:**

</div>

```
اگر به هر دلیلی assertGroupBalances دور زده شد،
job شبانه آن را می‌گیرد:

php artisan ledger:reconcile

  ⛔ Unbalanced transaction group found: g99 (GOLD: -250000)
  ⛔ System gold total: -250000 (expected 0)

  ► BalanceDiscrepancyDetected event
  ► انجماد سازمان‌های متأثر
  ► هشدار بحرانی به تیم عملیات
```

<div dir="rtl">

**این لایه‌های دفاعی چندگانه، دلیل اصلی قابل اعتماد بودن معماری است.**

</div>
