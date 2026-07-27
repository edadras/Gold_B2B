<div dir="rtl">

# ۴. بازار، سفارش و موتور تطبیق

## ۴.۱ ابزار معاملاتی (Instrument)

بازار حول «ابزار» شکل می‌گیرد، نه حول «طلا» به‌طور کلی.

</div>

```
Instrument
──────────────────────────────────────────────
  code            GOLD-995-T0
  metal_type      GOLD
  min_purity_x10  9950          عیار حداقل 995
  quote_unit      GRAM_FINE     قیمت بر حسب گرم خالص
  settlement_type T0            تسویه همان روز
  tick_size       10000         حداقل گام قیمت (ریال)
  lot_size        1000          حداقل گام وزن (میلی‌گرم = 1 گرم)
  min_order_mg    50000         حداقل سفارش 50 گرم
  max_order_mg    50000000      حداکثر سفارش 50 کیلوگرم
  status          ACTIVE | PAUSED | CLOSED
```

<div dir="rtl">

**ابزارهای پیشنهادی فاز ۲:**

| کد | توضیح |
|---|---|
| `GOLD-995-T0` | آب‌شده عیار ≥۹۹۵، تسویه امروز — ابزار اصلی |
| `GOLD-995-T1` | همان، تسویه فردا |
| `GOLD-750-T0` | آب‌شده عیار ≥۷۵۰ |
| `GOLD-900-T0` | آب‌شده عیار ≥۹۰۰ |

**نکته مهم:** قیمت همیشه بر حسب **گرم طلای خالص** اعلام می‌شود، نه گرم
ناخالص. این کار مقایسه بین عیارهای مختلف را ممکن می‌کند و یکی از
منابع اصلی سردرگمی بازار را حذف می‌کند.

---

## ۴.۲ ساختار سفارش

</div>

```
Order
──────────────────────────────────────────────────────
  id                 ORD-00044120
  organization_id    184
  created_by_user_id 512
  representative_id  88          (مسئولیت‌پذیری)

  instrument_id      GOLD-995-T0
  side               BUY | SELL
  type               MARKET | LIMIT | RFQ_QUOTE
  time_in_force      DAY | GTC | GTD | IOC | FOK

  quantity_mg        250000      (250 گرم خالص)
  filled_mg          100000      (تاکنون اجرا شده)
  remaining_mg       150000

  price_rial         78510000    (هر گرم خالص) — NULL برای MARKET
  max_slippage_bps   50          (فقط MARKET)

  reservation_entry_id  ارجاع به قفل دفتر

  status             PENDING | OPEN | PARTIALLY_FILLED |
                     FILLED | CANCELLED | REJECTED | EXPIRED

  expires_at         (برای GTD)
  placed_at, updated_at

  reject_reason
  metadata           JSON
```

<div dir="rtl">

### ماشین حالت سفارش

</div>

```
                  ┌─────────┐
                  │ PENDING │  در حال اعتبارسنجی و رزرو
                  └────┬────┘
           ┌───────────┼───────────┐
           ▼                       ▼
     ┌──────────┐            ┌──────────┐
     │ REJECTED │            │   OPEN   │
     └──────────┘            └────┬─────┘
     - موجودی ناکافی              │
     - سقف نقض شده        ┌───────┼───────┬────────────┐
     - بازار بسته          │       │       │            │
     - ابزار غیرفعال       ▼       ▼       ▼            ▼
                   ┌───────────────┐ ┌────────┐ ┌───────────┐ ┌─────────┐
                   │PARTIALLY_FILLED│ │ FILLED │ │ CANCELLED │ │ EXPIRED │
                   └───────┬───────┘ └────────┘ └───────────┘ └─────────┘
                           │
                    ┌──────┼──────┬──────────┐
                    ▼      ▼      ▼          ▼
                 FILLED  CANCELLED  EXPIRED
```

<div dir="rtl">

### انواع Time-in-Force

| نوع | رفتار |
|---|---|
| `DAY` | تا پایان جلسه معاملاتی معتبر است — **پیش‌فرض** |
| `GTC` | تا لغو دستی (Good Till Cancelled) |
| `GTD` | تا تاریخ مشخص (Good Till Date) |
| `IOC` | فوراً هرچه ممکن است اجرا شود، بقیه لغو (Immediate Or Cancel) |
| `FOK` | یا کاملاً اجرا شود یا کاملاً لغو (Fill Or Kill) |

---

## ۴.۳ منطق رزرو هنگام ثبت سفارش

</div>

```
سفارش فروش 250g @ 78,510,000
        │
        ▼
رزرو از دفتر طلا:
   AVAILABLE −250,000 mg
   RESERVED  +250,000 mg
        │
        ▼
(کارمزد فروشنده در لحظه تسویه از ریال کسر می‌شود)


سفارش خرید 250g @ 78,510,000
        │
        ▼
محاسبه نیاز ریالی:
   مبلغ ناخالص = 250 × 78,510,000 = 19,627,500,000
   کارمزد خریدار (0.15%) = 29,441,250
   ─────────────────────────────────────────
   کل نیاز = 19,656,941,250 ریال
        │
        ▼
رزرو از دفتر ریال:
   AVAILABLE −19,656,941,250
   RESERVED  +19,656,941,250
```

<div dir="rtl">

### سفارش MARKET — مسئله رزرو

قیمت مشخص نیست، پس چقدر رزرو کنیم؟

</div>

```
راهکار: رزرو بر اساس بدترین قیمت قابل قبول

   worst_price = best_ask × (1 + max_slippage_bps / 10000)
   required    = quantity × worst_price × (1 + fee_rate)

اگر اجرا با قیمت بهتری انجام شد ► مابه‌التفاوت release می‌شود
اگر عمق بازار کافی نبود ► اجرای جزئی + لغو باقیمانده
```

<div dir="rtl">

---

## ۴.۴ موتور تطبیق (Matching Engine)

### الگوریتم اولویت: Price-Time

</div>

```
دفتر فروش (Ask) — مرتب: قیمت صعودی، سپس زمان صعودی
┌──────────────────────────────────────────────┐
│  78,480,000    150 g    ORD-4401  09:12:33   │ ◄── اولویت ۱
│  78,480,000    200 g    ORD-4408  09:15:02   │ ◄── اولویت ۲ (زمان)
│  78,500,000    500 g    ORD-4395  09:08:11   │ ◄── اولویت ۳ (قیمت)
│  78,520,000  1,000 g    ORD-4412  09:20:44   │
└──────────────────────────────────────────────┘
        ▲
        │  Spread = 60,000
        ▼
دفتر خرید (Bid) — مرتب: قیمت نزولی، سپس زمان صعودی
┌──────────────────────────────────────────────┐
│  78,420,000    300 g    ORD-4405  09:14:20   │
│  78,400,000    250 g    ORD-4399  09:10:05   │
│  78,350,000    800 g    ORD-4390  09:05:55   │
└──────────────────────────────────────────────┘
```

<div dir="rtl">

### شبه‌کد تطبیق

</div>

```
function match(Order $incoming):
    trades = []
    remaining = incoming.quantity_mg

    opposites = SELECT * FROM orders
                WHERE instrument_id = incoming.instrument_id
                  AND side = opposite(incoming.side)
                  AND status IN ('OPEN','PARTIALLY_FILLED')
                  AND organization_id != incoming.organization_id   ◄ منع Self-Trade
                  AND (incoming.type = 'MARKET'
                       OR (incoming.side = BUY  AND price <= incoming.price)
                       OR (incoming.side = SELL AND price >= incoming.price))
                ORDER BY
                  CASE WHEN incoming.side = BUY THEN price END ASC,
                  CASE WHEN incoming.side = SELL THEN price END DESC,
                  placed_at ASC
                FOR UPDATE SKIP LOCKED
                LIMIT 100

    for each maker in opposites:
        if remaining == 0: break

        qty = min(remaining, maker.remaining_mg)

        # ⚠️ قیمت اجرا = قیمت سفارش قدیمی‌تر (maker)
        executionPrice = maker.price_rial

        # بررسی slippage برای MARKET
        if incoming.type == MARKET:
            if exceedsSlippage(executionPrice, incoming): break

        trade = createTrade(
            maker: maker,
            taker: incoming,
            quantity: qty,
            price: executionPrice,
        )
        trades.append(trade)

        maker.filled_mg += qty
        remaining -= qty

        updateOrderStatus(maker)

    incoming.filled_mg = incoming.quantity_mg - remaining
    updateOrderStatus(incoming)

    if incoming.type == FOK and remaining > 0:
        rollback()   # کاملاً لغو
        return []

    if incoming.type == IOC and remaining > 0:
        cancelRemaining(incoming)

    return trades
```

<div dir="rtl">

### قاعده قیمت اجرا

**قیمت اجرا همیشه قیمت `maker` (سفارش قدیمی‌تر در دفتر) است.**

</div>

```
مثال:
  در دفتر: فروش 100g @ 78,480,000  (maker)
  ورودی:   خرید 100g @ 78,510,000  (taker)

  اجرا @ 78,480,000   ◄── خریدار 30,000 ریال/گرم صرفه‌جویی کرد

دلیل: maker نقدشوندگی فراهم کرده و پاداش می‌گیرد.
      این استاندارد جهانی بازارهای دفتر سفارش است.
```

<div dir="rtl">

### منع Self-Trade

</div>

```
❌ سازمان A نمی‌تواند با سازمان A معامله کند

چرا مهم است:
  · می‌تواند برای دستکاری حجم و قیمت استفاده شود (Wash Trading)
  · از نظر AML قرمزپرچم است
  · معنای اقتصادی ندارد

پیاده‌سازی: WHERE organization_id != incoming.organization_id
+ بررسی مجدد پیش از ایجاد Trade
+ ثبت هرگونه تلاش در AML log
```

<div dir="rtl">

---

## ۴.۵ ساختار معامله (Trade)

</div>

```
Trade
────────────────────────────────────────────────────────
  id                    TRD-00088231
  instrument_id         GOLD-995-T0
  trade_source          ORDER_BOOK | OTC | RFQ

  buy_order_id          ORD-00044120
  sell_order_id         ORD-00044101
  buyer_organization_id 291
  seller_organization_id 184

  maker_side            SELL          (کدام طرف maker بود)

  ── مقادیر ────────────────────────────────────────────
  quantity_fine_mg      250000        250 گرم خالص
  price_per_gram_rial   78480000
  gross_amount_rial     19620000000   250 × 78,480,000

  ── کارمزد و مالیات ───────────────────────────────────
  buyer_fee_rial        29430000      0.15%
  seller_fee_rial       19620000      0.10% (تخفیف maker)
  tax_rial              0             (طبق مقررات)
  buyer_net_rial        19649430000   پرداختی خریدار
  seller_net_rial       19600380000   دریافتی فروشنده

  ── تحویل ─────────────────────────────────────────────
  settlement_type       T0 | T1 | Tn
  delivery_type         VAULT_TRANSFER | PHYSICAL | HOLD
  settlement_deadline   1404-08-05 17:00

  ── وضعیت ─────────────────────────────────────────────
  status                (به ۰۵-settlement.md مراجعه شود)

  executed_at
  settlement_id
```

<div dir="rtl">

### توجه: `Trade` تغییرناپذیر است

پس از ایجاد، هیچ فیلد مالی `Trade` تغییر نمی‌کند. اصلاح فقط با
معامله معکوس (`Reversal Trade`) انجام می‌شود.

---

## ۴.۶ معامله OTC

</div>

```
عضو A                          عضو B
   │                              │
   │ ۱) ایجاد پیشنهاد OTC          │
   │    ┌────────────────────┐     │
   │    │ فروش 500g @ 78.5M  │     │
   │    │ عیار ≥ 995         │     │
   │    │ تسویه: T0          │     │
   │    │ اعتبار: 30 دقیقه   │     │
   │    │ گیرنده: عضو B      │     │
   │    └────────────────────┘     │
   ├─────────────────────────────►│
   │                              │
   │      ۲) رزرو طلای A           │
   │                              │
   │  ۳) پذیرش / رد / پیشنهاد متقابل│
   │◄─────────────────────────────┤
   │                              │
   │      ۴) ایجاد Trade           │
   │      ۵) رزرو ریال B           │
   │      ۶) Settlement            │
```

<div dir="rtl">

**تفاوت‌های OTC با Order Book:**

| ویژگی | Order Book | OTC |
|---|---|---|
| طرف مقابل | ناشناس، خودکار | مشخص و انتخابی |
| قیمت | بازار تعیین می‌کند | توافقی |
| افشا | در عمق بازار دیده می‌شود | خصوصی |
| اثر بر Last Price | ✅ | ❌ (فقط در VWAP) |
| پیشنهاد متقابل | ❌ | ✅ |
| حداقل حجم | کم | معمولاً بالا |

**پیشنهاد متقابل (Counter-Offer):**

</div>

```
A: فروش 500g @ 78,500,000
        │
        ▼
B: پیشنهاد متقابل ► 500g @ 78,450,000
        │
        ▼
A: پیشنهاد متقابل ► 500g @ 78,480,000
        │
        ▼
B: پذیرش ► Trade

هر مرحله در تاریخچه ثبت می‌شود (برای Audit و رفع اختلاف)
حداکثر ۵ رفت‌وبرگشت، سپس انقضا
```

<div dir="rtl">

---

## ۴.۷ RFQ — درخواست قیمت

</div>

```
Rfq
────────────────────────────────────────────
  id                  RFQ-00001204
  organization_id     291         (درخواست‌کننده)
  side                BUY
  instrument_id       GOLD-995-T0
  quantity_mg         5000000     (5 کیلوگرم)
  min_purity_x10      9950
  settlement_type     T0
  delivery_type       VAULT_TRANSFER

  visibility          SELECTED | ALL_QUALIFIED | ANONYMOUS
  recipient_org_ids   [12, 45, 88, ...]

  expires_at          15 دقیقه
  allow_partial       true
  status              OPEN | QUOTED | ACCEPTED | EXPIRED | CANCELLED


RfqQuote
────────────────────────────────────────────
  id                  QTE-00003821
  rfq_id              RFQ-00001204
  quoter_org_id       45
  quantity_mg         5000000
  price_per_gram_rial 78480000
  valid_until
  soft_reservation_id (قفل نرم موجودی)
  status              PENDING | ACCEPTED | REJECTED | EXPIRED | WITHDRAWN
```

<div dir="rtl">

### قواعد RFQ

</div>

```
۱) پیشنهاددهنده در لحظه ارسال، موجودی خود را «قفل نرم» می‌کند
   ► موجودی قابل استفاده در Order Book باقی می‌ماند
   ► اما سیستم هشدار می‌دهد اگر مجموع قفل‌های نرم > موجودی

۲) پذیرش پیشنهاد ► قفل نرم به قفل سخت تبدیل می‌شود
   ► اگر موجودی در این فاصله مصرف شده باشد:
     ✗ پیشنهاد رد می‌شود
     ✗ ثبت منفی در Reputation پیشنهاددهنده

۳) پیشنهاد پس از ارسال قابل ویرایش نیست (فقط لغو تا قبل از پذیرش)

۴) درخواست‌کننده می‌تواند چند پیشنهاد را همزمان بپذیرد (تا سقف حجم)

۵) در حالت ANONYMOUS، هویت درخواست‌کننده تا لحظه پذیرش مخفی است
```

<div dir="rtl">

---

## ۴.۸ جلسه معاملاتی (Market Session)

</div>

```
MarketSession
  instrument_id
  session_date
  opens_at         09:00
  closes_at        17:30
  pre_open_at      08:45      (فقط ثبت سفارش، بدون تطبیق)
  status           SCHEDULED | PRE_OPEN | OPEN | PAUSED | CLOSED

  opening_price
  closing_price
  high_price
  low_price
  volume_mg
  trade_count
```

<div dir="rtl">

</div>

```
08:45 ─────► PRE_OPEN
             · ثبت سفارش مجاز
             · تطبیق انجام نمی‌شود
             · دفتر نمایش داده می‌شود (بدون اجرا)

09:00 ─────► OPEN
             · حراج بازگشایی (Opening Auction) — تطبیق دسته‌ای
             · سپس معاملات پیوسته

17:30 ─────► CLOSED
             · سفارش‌های DAY لغو می‌شوند
             · GTC باقی می‌مانند
             · محاسبه قیمت پایانی
             · شروع فرآیند تهاتر
```

<div dir="rtl">

### Circuit Breaker

</div>

```
اگر در بازه ۵ دقیقه‌ای:
    |قیمت فعلی − قیمت مرجع| / قیمت مرجع  >  ۳٪
    ► PAUSED به مدت ۱۵ دقیقه
    ► اعلان به همه اعضا
    ► امکان لغو سفارش، عدم امکان ثبت جدید

اگر منبع قیمت مرجع در دسترس نباشد بیش از ۵ دقیقه:
    ► PAUSED تا بازگشت منبع

بازگشایی پس از توقف ► حراج بازگشایی
```

<div dir="rtl">

---

## ۴.۹ داده بازار (Market Data)

### عمق بازار (Order Book Depth)

</div>

```json
{
  "instrument": "GOLD-995-T0",
  "timestamp": "2026-07-27T09:15:33.412Z",
  "bids": [
    { "price": 78420000, "quantity_mg": 300000, "order_count": 2 },
    { "price": 78400000, "quantity_mg": 250000, "order_count": 1 },
    { "price": 78350000, "quantity_mg": 800000, "order_count": 3 }
  ],
  "asks": [
    { "price": 78480000, "quantity_mg": 350000, "order_count": 2 },
    { "price": 78500000, "quantity_mg": 500000, "order_count": 1 },
    { "price": 78520000, "quantity_mg": 1000000, "order_count": 1 }
  ],
  "spread": 60000,
  "mid_price": 78450000
}
```

<div dir="rtl">

**قواعد افشا:**
- هویت سفارش‌دهنده **هرگز** در عمق بازار نمایش داده نمی‌شود
- `order_count` نمایش داده می‌شود اما نه اندازه هر سفارش جداگانه
- عمق نمایش‌داده‌شده به ۱۰ سطح محدود است
- به‌روزرسانی حداکثر ۱۰ بار در ثانیه (throttle)

### نوار معاملات (Trade Tape)

</div>

```json
{
  "instrument": "GOLD-995-T0",
  "trades": [
    { "time": "09:15:33", "price": 78480000, "quantity_mg": 100000, "side": "BUY" },
    { "time": "09:14:02", "price": 78480000, "quantity_mg": 250000, "side": "SELL" }
  ]
}
```

<div dir="rtl">

`side` نشان می‌دهد کدام طرف `taker` بوده — شاخص فشار خرید/فروش.
معاملات OTC در این نوار **نمایش داده نمی‌شوند**.

---

## ۴.۱۰ ملاحظات کارایی

| مورد | راهکار |
|---|---|
| کوئری عمق بازار | کش Redis با TTL کوتاه، بی‌اعتبارسازی رویدادمحور |
| به‌روزرسانی WebSocket | تجمیع تغییرات در پنجره ۱۰۰ms |
| ایندکس دفتر سفارش | `(instrument_id, side, status, price, placed_at)` |
| قفل | `SKIP LOCKED` برای موازی‌سازی |
| سفارش‌های بسته | انتقال به جدول آرشیو پس از ۹۰ روز |

</div>
