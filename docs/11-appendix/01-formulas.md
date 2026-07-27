<div dir="rtl">

# ۱. فرمول‌ها و مرجع محاسباتی

این سند مرجع واحد همه فرمول‌های سیستم است. هر پیاده‌سازی (PHP، Dart،
TypeScript، SQL) باید دقیقاً از این فرمول‌ها پیروی کند.

---

## ۱.۱ ثوابت

</div>

```
GRAM_TO_MILLIGRAM        = 1_000
MESGHAL_TO_GRAM          = 4.6083
MESGHAL_TO_MILLIGRAM     = 4_608.3        ► ذخیره: 46_083 (×10)
TROY_OUNCE_TO_GRAM       = 31.1034768
TROY_OUNCE_TO_MILLIGRAM  = 31_103.4768    ► ذخیره: 311_034_768 (×10^4)

PURITY_SCALE             = 10_000         ► عیار ۹۹۵ ► 9950
PERCENTAGE_SCALE         = 100_000        ► ۰.۱۵٪ ► 150
BASIS_POINT_SCALE        = 10_000         ► ۱٪ ► 100 bps
```

<div dir="rtl">

---

## ۱.۲ وزن و عیار

### F1 — وزن خالص از وزن ناخالص

</div>

```
fine_weight_mg = floor(gross_weight_mg × purity_x10 / 10000)

مثال:
  gross = 250,000 mg (250 گرم)
  purity_x10 = 9950 (عیار ۹۹۵)
  fine = floor(250,000 × 9,950 / 10,000)
       = floor(2,487,500,000 / 10,000)
       = floor(248,750)
       = 248,750 mg = 248.750 گرم
```

<div dir="rtl">

> **جهت گِردکردن: FLOOR** — سیستم هرگز طلایی که ندارد ثبت نمی‌کند.

### F2 — وزن ناخالص لازم برای وزن خالص مشخص

</div>

```
gross_weight_mg = ceil(fine_weight_mg × 10000 / purity_x10)

مثال:
  می‌خواهیم 100,000 mg خالص از طلای عیار ۷۵۰
  gross = ceil(100,000 × 10,000 / 7,500)
        = ceil(1,000,000,000 / 7,500)
        = ceil(133,333.33)
        = 133,334 mg
```

<div dir="rtl">

> **جهت گِردکردن: CEIL** — باید کافی باشد، نه کمتر.

### F3 — عیار مؤثر از چند قطعه

</div>

```
Σ fine_i
────────  × 10000  = عیار مؤثر
Σ gross_i

مثال:
  قطعه ۱: 40,000 mg @ 7500 ► fine 30,000
  قطعه ۲: 35,000 mg @ 7500 ► fine 26,250
  قطعه ۳: 25,000 mg @ 9950 ► fine 24,875

  Σ gross = 100,000
  Σ fine  =  81,125

  عیار مؤثر = floor(81,125 × 10,000 / 100,000) = 8,112
             ► عیار ۸۱۱.۲
```

<div dir="rtl">

### F4 — تبدیل واحد

</div>

```
گرم ► میلی‌گرم:      mg = round(g × 1000)
میلی‌گرم ► گرم:      g  = mg / 1000            (فقط نمایش)

مثقال ► میلی‌گرم:    mg = round(m × 46083 / 10)
میلی‌گرم ► مثقال:    m  = mg × 10 / 46083       (فقط نمایش)

اونس ► میلی‌گرم:     mg = round(oz × 311034768 / 10000)
میلی‌گرم ► اونس:     oz = mg × 10000 / 311034768  (فقط نمایش)
```

<div dir="rtl">

---

## ۱.۳ قیمت و ارزش

### F5 — مبلغ ناخالص معامله

</div>

```
gross_amount_rial = floor(fine_weight_mg × price_per_fine_gram_rial / 1000)

مثال:
  fine = 248,750 mg
  price = 78,480,000 ریال/گرم

  gross = floor(248,750 × 78,480,000 / 1,000)
        = floor(19,521,900,000,000 / 1,000)
        = 19,521,900,000 ریال
```

<div dir="rtl">

> ⚠️ `248,750 × 78,480,000 = 19,521,900,000,000` — این عدد
> در محدوده `BIGINT` (تا ۹.۲×۱۰^۱۸) جای می‌گیرد، اما در
> وزن‌های خیلی بزرگ باید از `bcmath` استفاده شود.

### F6 — ارزش ذاتی هر گرم طلای خالص

</div>

```
                ounce_usd × usd_irr
price_per_g = ─────────────────────
                    31.1034768

با اعداد صحیح:
  ounce_usd_micro = قیمت اونس × 10^6
  OUNCE_MG_X10000 = 311_034_768

  price_per_fine_gram_rial =
      floor( ounce_usd_micro × usd_irr × 10^4 / (10^6 × OUNCE_MG_X10000) × 1000 )

  ساده‌شده:
      floor( ounce_usd_micro × usd_irr × 10 / OUNCE_MG_X10000 )

مثال:
  ounce = $2,650.40    ► ounce_usd_micro = 2,650,400,000
  usd_irr = 620,000

  = floor(2,650,400,000 × 620,000 × 10 / 311,034,768)
  = floor(16,432,480,000,000,000 / 311,034,768)
  = 52,831,649 ریال/گرم
```

<div dir="rtl">

### F7 — حباب (Premium)

</div>

```
premium_bps = floor((market_price − intrinsic_price) × 10000 / intrinsic_price)

مثال:
  market = 78,480,000
  intrinsic = 52,831,649

  premium_bps = floor((78,480,000 − 52,831,649) × 10,000 / 52,831,649)
              = floor(25,648,351 × 10,000 / 52,831,649)
              = floor(4,854.7)
              = 4,854 bps = 48.54٪
```

<div dir="rtl">

---

## ۱.۴ کارمزد و مبالغ خالص

### F8 — کارمزد

</div>

```
fee_rial = ceil(gross_amount_rial × rate_x100k / 100000)

سپس اعمال کف و سقف:
  if (min_fee != null && fee < min_fee)  fee = min_fee
  if (max_fee != null && fee > max_fee)  fee = max_fee

مثال:
  gross = 19,521,900,000
  rate = 150 (۰.۱۵٪)

  fee = ceil(19,521,900,000 × 150 / 100,000)
      = ceil(2,928,375,000,000 / 100,000)
      = ceil(29,282,850)
      = 29,282,850 ریال
```

<div dir="rtl">

> **جهت گِردکردن: CEIL** — به نفع سامانه، اما شفاف اعلام‌شده.

### F9 — مبالغ خالص

</div>

```
⚠️ قاعده حیاتی: یکی محاسبه، بقیه با تفریق

buyer_net_rial  = gross_amount_rial + buyer_fee_rial + tax_rial
seller_net_rial = gross_amount_rial − seller_fee_rial − tax_seller_rial

بررسی الزامی (باید همیشه برقرار باشد):
  buyer_net = gross + buyer_fee + tax
  gross     = seller_net + seller_fee + tax_seller

  platform_income = buyer_fee + seller_fee
  cash_flow_check: buyer_net − seller_net = buyer_fee + seller_fee + همه مالیات‌ها
```

<div dir="rtl">

### مثال کامل

</div>

```
ورودی:
  gross_weight  = 250,000 mg
  purity_x10    = 9,950
  price/g       = 78,480,000 ریال
  buyer_rate    = 150   (۰.۱۵٪)
  seller_rate   = 100   (۰.۱۰٪)
  tax           = 0

محاسبه:
  F1: fine        = floor(250,000 × 9,950 / 10,000)     = 248,750 mg
  F5: gross       = floor(248,750 × 78,480,000 / 1,000) = 19,521,900,000
  F8: buyer_fee   = ceil(19,521,900,000 × 150 / 100,000)= 29,282,850
  F8: seller_fee  = ceil(19,521,900,000 × 100 / 100,000)= 19,521,900
  F9: buyer_net   = 19,521,900,000 + 29,282,850 + 0     = 19,551,182,850
  F9: seller_net  = 19,521,900,000 − 19,521,900 − 0     = 19,502,378,100

بررسی بقای جرم:
  buyer_net − seller_net = 19,551,182,850 − 19,502,378,100 = 48,804,750
  buyer_fee + seller_fee = 29,282,850 + 19,521,900        = 48,804,750  ✅
```

<div dir="rtl">

---

## ۱.۵ رزرو موجودی

### F10 — مبلغ رزرو برای سفارش خرید

</div>

```
سفارش LIMIT:
  required_rial = floor(qty_mg × price / 1000)
                + ceil(floor(qty_mg × price / 1000) × buyer_rate / 100000)
                + tax

سفارش MARKET:
  worst_price   = ceil(best_ask × (10000 + max_slippage_bps) / 10000)
  required_rial = محاسبه با worst_price

مثال MARKET:
  qty = 250,000 mg
  best_ask = 78,480,000
  max_slippage_bps = 50 (۰.۵٪)

  worst_price = ceil(78,480,000 × 10,050 / 10,000) = 78,872,400
  gross       = floor(250,000 × 78,872,400 / 1,000) = 19,718,100,000
  fee         = ceil(19,718,100,000 × 150 / 100,000) = 29,577,150
  required    = 19,747,677,150 ریال
```

<div dir="rtl">

### F11 — رزرو برای سفارش فروش

</div>

```
required_gold_mg = qty_mg

⚠️ کارمزد فروشنده از ریال کسر می‌شود، نه از طلا.
   بنابراین رزرو طلا دقیقاً برابر مقدار سفارش است.
```

<div dir="rtl">

---

## ۱.۶ تهاتر

### F12 — تهاتر دوطرفه

</div>

```
برای جفت (A, B) و دارایی مشخص:

  net(A→B) = Σ obligations(A→B) − Σ obligations(B→A)

  if net > 0:  A به B بدهکار است به مقدار net
  if net < 0:  B به A بدهکار است به مقدار |net|
  if net = 0:  تسویه کامل، بدون انتقال
```

<div dir="rtl">

### F13 — تهاتر چندطرفه

</div>

```
برای هر عضو i:

  net_position(i) = Σ obligations(*→i) − Σ obligations(i→*)

ثابت الزامی:
  Σ net_position(i) برای همه i = 0

اجرا:
  if net_position(i) < 0:  i → CCP به مقدار |net_position(i)|
  if net_position(i) > 0:  CCP → i به مقدار net_position(i)
```

<div dir="rtl">

---

## ۱.۷ حسابداری موجودی

### F14 — میانگین موزون بهای تمام‌شده

</div>

```
هنگام خرید:
  new_qty  = old_qty + purchase_qty
  new_cost = old_total_cost + purchase_cost
  new_avg  = floor(new_cost × 1000 / new_qty)     ► ریال به ازای گرم

مثال:
  old:  100,000 mg @ avg 75,000,000 ► total 7,500,000,000
  buy:  200,000 mg cost 15,600,000,000

  new_qty  = 300,000 mg
  new_cost = 23,100,000,000
  new_avg  = floor(23,100,000,000 × 1000 / 300,000) = 77,000,000
```

<div dir="rtl">

### F15 — بهای تمام‌شده کالای فروش‌رفته

</div>

```
cogs = floor(sold_qty_mg × avg_cost_per_gram / 1000)

مثال:
  sold = 150,000 mg
  avg  = 77,000,000

  cogs = floor(150,000 × 77,000,000 / 1,000) = 11,550,000,000
```

<div dir="rtl">

### F16 — سود تحقق‌یافته

</div>

```
realized_profit = sale_revenue − cogs − fees

مثال:
  revenue = 12,000,000,000
  cogs    = 11,550,000,000
  fees    =     12,000,000

  profit  =    438,000,000 ریال
```

<div dir="rtl">

### F17 — سود تحقق‌نیافته

</div>

```
unrealized = floor(qty_mg × current_market_price / 1000) − book_value

مثال:
  qty = 150,000 mg
  market_price = 78,480,000
  book_value = floor(150,000 × 77,000,000 / 1,000) = 11,550,000,000

  market_value = floor(150,000 × 78,480,000 / 1,000) = 11,772,000,000
  unrealized = 222,000,000 ریال
```

<div dir="rtl">

---

## ۱.۸ ریسک

### F18 — تعهدات باز

</div>

```
gold_exposure_mg =
    Σ settlements.fine_weight_mg
        WHERE gold_deliverer = org
          AND status NOT IN (SETTLED, COMPLETED, CANCELLED, REVERSED)
  + Σ (orders.quantity_mg − orders.filled_mg)
        WHERE org = org AND side = SELL
          AND status IN (OPEN, PARTIALLY_FILLED)
```

<div dir="rtl">

### F19 — امتیاز اعتباری

</div>

```
score = Σ (component_i × weight_i)

components:
  on_time_rate     = settlements_on_time / settlements_total
  tenure           = min(months_active / 24, 1)
  volume           = min(log10(total_volume_mg / 1000) / 6, 1)
  dispute_clean    = 1 − min(disputes_lost / max(total_trades, 1) × 100, 1)
  kyc_complete     = verified_items / total_items
  cp_diversity     = min(distinct_counterparties / 20, 1)
  aml_clean        = active_flags == 0 ? 1 : 0

weights:
  on_time_rate  × 350
  tenure        × 150
  volume        × 150
  dispute_clean × 150
  kyc_complete  × 100
  cp_diversity  ×  50
  aml_clean     ×  50
  ──────────────────
  حداکثر         1000
```

<div dir="rtl">

### F20 — نسبت پوشش وثیقه

</div>

```
coverage_ratio_bps = floor(collateral_value × 10000 / open_exposure_value)

آستانه‌ها:
  ≥ 15000 (۱۵۰٪)  ► سالم
  12000–14999     ► هشدار
  11000–11999     ► Margin Call
  < 11000         ► اجرای جزئی
```

<div dir="rtl">

### F21 — جریمه تأخیر

</div>

```
penalty = min(
    floor(amount × daily_rate_x100k × days_overdue / 100000),
    floor(amount × max_penalty_x100k / 100000)
)

مثال:
  amount = 19,521,900,000
  daily_rate = 50 (۰.۰۵٪)
  days = 3
  max = 10000 (۱۰٪)

  penalty = min(
      floor(19,521,900,000 × 50 × 3 / 100,000),
      floor(19,521,900,000 × 10,000 / 100,000)
  )
  = min(29,282,850, 1,952,190,000)
  = 29,282,850 ریال
```

<div dir="rtl">

---

## ۱.۹ قیمت بازار

### F22 — VWAP

</div>

```
             Σ (price_i × qty_i)
VWAP = floor(───────────────────)
                  Σ qty_i

مثال:
  معامله ۱: 100,000 mg @ 78,480,000
  معامله ۲: 250,000 mg @ 78,500,000
  معامله ۳:  50,000 mg @ 78,420,000

  numerator = 100,000×78,480,000 + 250,000×78,500,000 + 50,000×78,420,000
            = 7,848,000,000,000 + 19,625,000,000,000 + 3,921,000,000,000
            = 31,394,000,000,000
  denominator = 400,000

  VWAP = floor(31,394,000,000,000 / 400,000) = 78,485,000
```

<div dir="rtl">

### F23 — اسپرد

</div>

```
spread_rial = best_ask − best_bid
spread_bps  = floor(spread_rial × 10000 / mid_price)
mid_price   = floor((best_ask + best_bid) / 2)
```

<div dir="rtl">

### F24 — Circuit Breaker

</div>

```
deviation_bps = floor(abs(current − reference) × 10000 / reference)

if deviation_bps > circuit_breaker_threshold_bps:
    ► توقف بازار

مثال:
  reference = 78,000,000
  current   = 80,500,000
  threshold = 300 (۳٪)

  deviation = floor(2,500,000 × 10,000 / 78,000,000) = 320 bps = ۳.۲٪
  320 > 300 ► توقف  ⛔
```

<div dir="rtl">

---

## ۱.۱۰ جدول خلاصه جهت گِردکردن

| # | محاسبه | جهت | دلیل |
|---|---|---|---|
| F1 | وزن خالص | FLOOR | محافظه‌کارانه |
| F2 | وزن ناخالص لازم | CEIL | باید کافی باشد |
| F3 | عیار مؤثر | FLOOR | محافظه‌کارانه |
| F5 | مبلغ ناخالص | FLOOR | محافظه‌کارانه |
| F6 | ارزش ذاتی | FLOOR | — |
| F8 | کارمزد | CEIL | به نفع سامانه، شفاف |
| F9 | مبلغ خالص | تفریق | تضمین تراز |
| F10 | رزرو خرید | محاسبه کامل | باید کافی باشد |
| F14 | میانگین بها | FLOOR | محافظه‌کارانه |
| F15 | بهای فروش‌رفته | FLOOR | محافظه‌کارانه |
| F22 | VWAP | FLOOR | — |

**باقیمانده هر گِردکردن** به حساب سیستمی `ROUNDING_DIFFERENCE` منتقل
می‌شود تا بقای جرم حفظ شود.

---

## ۱.۱۱ موارد لبه‌ای (Edge Cases)

</div>

```
۱) عیار صفر
   fine = 0  ► lot بدون ارزش، اما رکورد باقی می‌ماند

۲) وزن یک میلی‌گرم با عیار ۹۹۹۹
   fine = floor(1 × 9,999 / 10,000) = 0
   ► باقیمانده ۰.۹۹۹۹ mg به ROUNDING منتقل می‌شود

۳) قیمت صفر
   ⛔ ممنوع — اعتبارسنجی مانع می‌شود

۴) وزن صفر
   ⛔ ممنوع

۵) سرریز BIGINT
   حداکثر مقدار: 9,223,372,036,854,775,807

   بدترین حالت واقع‌بینانه:
     ۱۰۰ کیلوگرم = 100,000,000 mg
     × 200,000,000 ریال/گرم = 2×10^16
     ► امن، اما با حاشیه کم

   ⚠️ محاسبات میانی (مثل mg × price) باید با bcmath انجام شود

۶) تقسیم بر صفر
   · avg_cost وقتی qty = 0  ► خروجی 0
   · premium وقتی intrinsic = 0  ► خروجی null، نه خطا
   · on_time_rate وقتی total = 0 ► خروجی null (نه ۱۰۰٪)

۷) مقادیر منفی
   · وزن: هرگز منفی نیست
   · مانده دفتر: فقط bucket PAYABLE می‌تواند منفی باشد
   · amount در ledger_entry: می‌تواند منفی باشد (بدهکار)
   · net_position در تهاتر: می‌تواند منفی باشد
```

<div dir="rtl">

---

## ۱.۱۲ موارد آزمون الزامی

</div>

```
هر پیاده‌سازی از این فرمول‌ها باید این موارد را پاس کند:

F1 (وزن خالص):
  (100000, 10000) → 100000
  (100000,  7500) →  75000
  (127420,  7500) →  95565
  (250000,  9950) → 248750
  (     1,  9999) →      0
  (     3,  3333) →      0
  (     0,  9950) →      0

F5 (مبلغ ناخالص):
  (248750, 78480000) → 19521900000
  (  1000, 78480000) →    78480000
  (     1, 78480000) →       78480

F8 (کارمزد):
  (19521900000, 150) → 29282850
  (          1, 150) →        1   ◄ CEIL
  (          0, 150) →        0

F9 (تراز):
  برای ۱۰۰۰ ترکیب تصادفی:
    gross == seller_net + seller_fee + tax   ✅
    buyer_net == gross + buyer_fee + tax     ✅
```

</div>
