<div dir="rtl">

# ۷. موتور قیمت‌گذاری

## ۷.۱ منابع قیمت

</div>

```
┌─────────────────────────────────────────────────────────────┐
│  منابع بیرونی                                                │
│                                                             │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐       │
│  │ اونس جهانی   │  │  نرخ ارز     │  │  مظنه بازار  │       │
│  │  XAU/USD     │  │  USD/IRR     │  │   داخلی      │       │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘       │
└─────────┼─────────────────┼─────────────────┼───────────────┘
          │                 │                 │
          └────────┬────────┴─────────────────┘
                   ▼
       ┌───────────────────────────┐
       │   Price Ingestion Layer   │
       │   · اعتبارسنجی            │
       │   · تشخیص پرش غیرعادی     │
       │   · fallback بین منابع    │
       └───────────┬───────────────┘
                   ▼
       ┌───────────────────────────┐
       │   Reference Price          │
       │   ارزش ذاتی محاسبه‌شده     │
       └───────────┬───────────────┘
                   ▼
       ┌───────────────────────────┐
       │   Market Price             │
       │   قیمت واقعی از معاملات    │
       │   Bid / Ask / Last / VWAP  │
       └───────────┬───────────────┘
                   ▼
       ┌───────────────────────────┐
       │   Premium / Discount       │
       │   حباب = بازار − ذاتی      │
       └───────────────────────────┘
```

<div dir="rtl">

---

## ۷.۲ محاسبه ارزش ذاتی

</div>

```
ورودی‌ها:
  ounce_usd      قیمت هر اونس تروا به دلار      مثلاً 2,650
  usd_irr        نرخ دلار به ریال               مثلاً 620,000
  GRAM_PER_OUNCE ثابت = 31.1034768

محاسبه:
  ارزش هر گرم طلای خالص (999.9):
      = ounce_usd × usd_irr / 31.1034768
      = 2,650 × 620,000 / 31.1034768
      = 52,824,000 ریال (تقریبی)

  ارزش هر گرم طلای عیار P:
      = ارزش گرم خالص × P / 1000
```

<div dir="rtl">

**پیاده‌سازی با اعداد صحیح:**

</div>

```php
namespace App\Modules\Pricing\Domain;

final class IntrinsicValueCalculator
{
    // 31.1034768 گرم × 10^7 برای حفظ دقت
    private const GRAM_PER_OUNCE_SCALED = 311_034_768;
    private const SCALE = 10_000_000;

    /**
     * @param int $ounceUsdMicro  قیمت اونس به دلار × 10^6
     * @param int $usdIrr         نرخ دلار به ریال (عدد صحیح)
     * @return int                ریال به ازای هر گرم طلای خالص
     */
    public function pricePerFineGram(int $ounceUsdMicro, int $usdIrr): int
    {
        // (ounceUsdMicro / 10^6) × usdIrr / (GRAM_PER_OUNCE_SCALED / 10^7)
        $numerator   = bcmul((string) $ounceUsdMicro, (string) $usdIrr);
        $numerator   = bcmul($numerator, (string) self::SCALE);
        $denominator = bcmul('1000000', (string) self::GRAM_PER_OUNCE_SCALED);

        return (int) bcdiv($numerator, $denominator, 0);
    }
}
```

<div dir="rtl">

---

## ۷.۳ ساختار داده قیمت

</div>

```sql
CREATE TABLE price_sources (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(50) NOT NULL UNIQUE,   -- 'ounce_primary'
  name            VARCHAR(191) NOT NULL,
  type            ENUM('OUNCE','FX','MESGHAL','MANUAL') NOT NULL,
  priority        TINYINT UNSIGNED NOT NULL,     -- ۱ = اصلی
  endpoint        VARCHAR(500) NULL,
  max_staleness_s INT UNSIGNED NOT NULL DEFAULT 300,
  max_deviation_bps INT UNSIGNED NOT NULL DEFAULT 500,  -- ۵٪
  status          ENUM('ACTIVE','DEGRADED','DOWN') NOT NULL,
  last_success_at TIMESTAMP NULL,
  last_error      VARCHAR(500) NULL
) ENGINE=InnoDB;

CREATE TABLE price_ticks (
  id              BIGINT UNSIGNED AUTO_INCREMENT,
  source_id       BIGINT UNSIGNED NOT NULL,
  price_type      ENUM('OUNCE_USD','USD_IRR','MESGHAL_IRR',
                       'FINE_GRAM_IRR') NOT NULL,
  value           BIGINT NOT NULL,
  scale           TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- تعداد رقم اعشار
  observed_at     TIMESTAMP(3) NOT NULL,     -- زمان اعلام منبع
  received_at     TIMESTAMP(3) NOT NULL,     -- زمان دریافت ما
  is_accepted     BOOLEAN NOT NULL DEFAULT TRUE,
  rejection_reason VARCHAR(200) NULL,
  PRIMARY KEY (id, received_at),
  KEY idx_type_time (price_type, received_at)
) ENGINE=InnoDB;

CREATE TABLE reference_prices (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  instrument_id   BIGINT UNSIGNED NOT NULL,
  fine_gram_rial  BIGINT UNSIGNED NOT NULL,
  ounce_usd_micro BIGINT UNSIGNED NOT NULL,
  usd_irr         BIGINT UNSIGNED NOT NULL,
  computed_at     TIMESTAMP(3) NOT NULL,
  source_tick_ids JSON NOT NULL,
  KEY idx_instrument_time (instrument_id, computed_at)
) ENGINE=InnoDB;

CREATE TABLE market_quotes (
  instrument_id   BIGINT UNSIGNED PRIMARY KEY,
  best_bid        BIGINT UNSIGNED NULL,
  best_bid_qty_mg BIGINT UNSIGNED NULL,
  best_ask        BIGINT UNSIGNED NULL,
  best_ask_qty_mg BIGINT UNSIGNED NULL,
  last_price      BIGINT UNSIGNED NULL,
  last_qty_mg     BIGINT UNSIGNED NULL,
  last_at         TIMESTAMP(3) NULL,
  day_open        BIGINT UNSIGNED NULL,
  day_high        BIGINT UNSIGNED NULL,
  day_low         BIGINT UNSIGNED NULL,
  day_volume_mg   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  day_vwap        BIGINT UNSIGNED NULL,
  day_trade_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at      TIMESTAMP(3) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE price_candles (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  instrument_id   BIGINT UNSIGNED NOT NULL,
  interval_code   ENUM('1m','5m','15m','1h','1d') NOT NULL,
  opened_at       TIMESTAMP NOT NULL,
  open_price      BIGINT UNSIGNED NOT NULL,
  high_price      BIGINT UNSIGNED NOT NULL,
  low_price       BIGINT UNSIGNED NOT NULL,
  close_price     BIGINT UNSIGNED NOT NULL,
  volume_mg       BIGINT UNSIGNED NOT NULL,
  trade_count     INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_inst_interval_time (instrument_id, interval_code, opened_at)
) ENGINE=InnoDB;
```

<div dir="rtl">

---

## ۷.۴ اعتبارسنجی ورودی قیمت

هر tick دریافتی از این فیلترها می‌گذرد:

</div>

```
۱) تازگی
   received_at − observed_at ≤ max_staleness_s
   ✗ رد: "STALE"

۲) انحراف از آخرین مقدار پذیرفته‌شده
   |value − last_accepted| / last_accepted ≤ max_deviation_bps
   ✗ رد: "OUTLIER"  ► نیاز به تأیید دستی

۳) انحراف از سایر منابع
   اگر ≥۲ منبع فعال دارند و اختلاف > ۲٪
   ► علامت‌گذاری، استفاده از میانه

۴) بازه منطقی
   0 < value < سقف تعریف‌شده
   ✗ رد: "OUT_OF_RANGE"

۵) یکنواختی زمانی
   observed_at باید بزرگ‌تر از tick قبلی همان منبع باشد
   ✗ رد: "OUT_OF_ORDER"
```

<div dir="rtl">

### راهبرد Fallback

</div>

```
منبع اولویت ۱ سالم است؟
   ✅ ► استفاده
   ❌ ▼
منبع اولویت ۲ سالم است؟
   ✅ ► استفاده + هشدار "DEGRADED"
   ❌ ▼
هیچ منبع خودکاری در دسترس نیست
   ► حالت MANUAL: اپراتور قیمت را وارد می‌کند
   ► هشدار به همه کاربران: «قیمت مرجع دستی»
   ❌ ▼
اگر بیش از ۵ دقیقه هیچ قیمتی نباشد
   ► ⛔ توقف بازار (Circuit Breaker)
```

<div dir="rtl">

---

## ۷.۵ حباب (Premium/Discount)

</div>

```
premium_bps = (market_price − intrinsic_value) / intrinsic_value × 10000

مثال:
  ارزش ذاتی      : 52,824,000 ریال/گرم خالص
  قیمت بازار     : 78,480,000 ریال/گرم خالص
  حباب           : +48.6%

  ⚠️ در بازار ایران این عدد به دلیل شکاف نرخ ارز طبیعی است
     و نباید به‌عنوان «قیمت‌گذاری اشتباه» تفسیر شود.
```

<div dir="rtl">

**کاربردها:**
- نمایش به کاربر برای تصمیم‌گیری
- تشخیص ناهنجاری (حباب ناگهانی منفی = احتمال دستکاری)
- Circuit Breaker مبتنی بر انحراف از ذاتی

---

## ۷.۶ قواعد نمایش قیمت

| نماد | تعریف | منبع |
|---|---|---|
| `BID` | بالاترین قیمت خرید در دفتر | Order Book |
| `ASK` | پایین‌ترین قیمت فروش در دفتر | Order Book |
| `SPREAD` | `ASK − BID` | محاسبه |
| `MID` | `(ASK + BID) / 2` | محاسبه |
| `LAST` | قیمت آخرین معامله **در Order Book** | Trade |
| `VWAP` | میانگین وزنی حجمی روز (شامل OTC) | Trade |
| `OPEN` | اولین معامله جلسه | Trade |
| `HIGH`/`LOW` | بیشینه/کمینه جلسه | Trade |
| `CHANGE` | `LAST − OPEN` و درصد آن | محاسبه |
| `REFERENCE` | ارزش ذاتی محاسبه‌شده | Pricing |

### قاعده مهم: OTC در `LAST` نمی‌آید

</div>

```
دلیل: یک معامله OTC ساختگی با قیمت غیرواقعی می‌تواند
      قیمت عمومی بازار را دستکاری کند.

راهکار:
  LAST  ► فقط معاملات Order Book
  VWAP  ► همه معاملات (شفافیت کامل حجم)

اگر Order Book در یک روز معامله نداشت:
  LAST = LAST روز قبل + نشانه "قدیمی"
```

<div dir="rtl">

---

## ۷.۷ ساخت شمع (Candle)

</div>

```php
// php artisan pricing:build-ohlc  (هر ۵ دقیقه)

foreach (Instrument::active() as $instrument) {
    foreach (['1m', '5m', '15m', '1h', '1d'] as $interval) {
        $bucket = $this->currentBucket($interval);

        $trades = Trade::where('instrument_id', $instrument->id)
            ->where('trade_source', 'ORDER_BOOK')      // فقط دفتر سفارش
            ->whereBetween('executed_at', [$bucket->start, $bucket->end])
            ->get();

        if ($trades->isEmpty()) {
            continue;   // شمع خالی ساخته نمی‌شود
        }

        PriceCandle::updateOrCreate(
            [
                'instrument_id' => $instrument->id,
                'interval_code' => $interval,
                'opened_at'     => $bucket->start,
            ],
            [
                'open_price'  => $trades->first()->price_per_gram_rial,
                'close_price' => $trades->last()->price_per_gram_rial,
                'high_price'  => $trades->max('price_per_gram_rial'),
                'low_price'   => $trades->min('price_per_gram_rial'),
                'volume_mg'   => $trades->sum('quantity_fine_mg'),
                'trade_count' => $trades->count(),
            ],
        );
    }
}
```

<div dir="rtl">

---

## ۷.۸ محدودیت قیمت سفارش

برای جلوگیری از سفارش‌های اشتباه (Fat Finger):

</div>

```
سفارش با قیمت P پذیرفته می‌شود اگر:

    |P − reference_price| / reference_price ≤ max_order_deviation

پیش‌فرض: ۱۰٪

اگر خارج از بازه:
  ► ⚠️ هشدار به کاربر با نمایش صریح انحراف
  ► نیاز به تأیید مجدد صریح
  ► ثبت در Audit با نشانه UNUSUAL_PRICE
  ► اگر انحراف > ۲۰٪ ► رد کامل
```

<div dir="rtl">

---

## ۷.۹ هشدار قیمتی کاربر

</div>

```
PriceAlert
  organization_id
  user_id
  instrument_id
  condition      ABOVE | BELOW | CHANGE_PERCENT
  threshold
  is_recurring
  status         ACTIVE | TRIGGERED | DISABLED
  triggered_at

مثال:
  «وقتی قیمت بالای ۸۰,۰۰۰,۰۰۰ رفت خبرم کن»
  «وقتی قیمت بیش از ۲٪ در یک ساعت تغییر کرد»
```

<div dir="rtl">

بررسی در هر به‌روزرسانی `market_quotes`، با throttle برای جلوگیری از
اعلان پی‌درپی.

---

## ۷.۱۰ نمایش در رابط کاربری

</div>

```
┌──────────────────────────────────────────────────────────────┐
│  GOLD-995-T0                          ۱۴۰۴/۰۸/۰۵  ۱۴:۲۳:۱۱  │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│   خرید (BID)          فروش (ASK)         آخرین (LAST)        │
│   78,420,000          78,480,000         78,450,000          │
│   300 g                350 g              ▲ +0.42%           │
│                                                              │
│   اسپرد: 60,000 (0.08%)                                      │
│                                                              │
├──────────────────────────────────────────────────────────────┤
│   بازگشایی    بیشترین     کمترین      حجم        VWAP        │
│   78,120,000  78,610,000  78,050,000  12.4 kg   78,390,000   │
├──────────────────────────────────────────────────────────────┤
│   ارزش ذاتی: 52,824,000    حباب: +48.6%                      │
│   اونس: $2,650.40   دلار: 620,000                            │
│   منبع: اصلی ✅   آخرین به‌روزرسانی: ۳ ثانیه پیش              │
└──────────────────────────────────────────────────────────────┘
```

<div dir="rtl">

**قواعد UI:**
- هر قیمت باید timestamp داشته باشد
- اگر قیمت بیش از ۳۰ ثانیه قدیمی است ► رنگ خاکستری + نشانه
- اگر منبع `DEGRADED` است ► نشانه هشدار
- اگر حالت `MANUAL` است ► بنر صریح در بالای صفحه
- هرگز قیمت بدون منشأ نمایش داده نشود

</div>
