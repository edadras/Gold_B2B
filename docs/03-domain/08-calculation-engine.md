<div dir="rtl">

# ۸. موتور محاسبات

> **اصل:** یک فرمول، یک پیاده‌سازی، یک نقطه.
> نباید فرمول محاسبه وزن خالص در پنل معامله‌گر با فرمول حسابداری فرق کند.

---

## ۸.۱ اشیاء ارزش (Value Objects)

همه محاسبات از طریق اشیاء تغییرناپذیر انجام می‌شود، نه عدد خام.

</div>

```php
namespace App\Modules\Shared\ValueObjects;

/** وزن — همیشه بر حسب میلی‌گرم */
final readonly class Weight
{
    private function __construct(public int $milligrams)
    {
        if ($milligrams < 0) {
            throw new InvalidArgumentException('Weight cannot be negative');
        }
    }

    public static function fromMilligrams(int $mg): self  { return new self($mg); }
    public static function fromGrams(float $g): self      { return new self((int) round($g * 1000)); }
    public static function fromMesghal(float $m): self    { return new self((int) round($m * 4608.3)); }
    public static function zero(): self                    { return new self(0); }

    public function grams(): string   { return bcdiv((string) $this->milligrams, '1000', 3); }
    public function mesghal(): string { return bcdiv((string) $this->milligrams, '4608.3', 4); }

    public function plus(Weight $o): self  { return new self($this->milligrams + $o->milligrams); }
    public function minus(Weight $o): self { return new self($this->milligrams - $o->milligrams); }

    public function isGreaterThan(Weight $o): bool { return $this->milligrams > $o->milligrams; }
    public function equals(Weight $o): bool        { return $this->milligrams === $o->milligrams; }
    public function isZero(): bool                 { return $this->milligrams === 0; }
}
```

<div dir="rtl">

</div>

```php
/** عیار — بر حسب ده‌هزارم (0 تا 10000)، یعنی عیار ۹۹۵ ► 9950 */
final readonly class Purity
{
    public const MIN = 0;
    public const MAX = 10_000;

    private function __construct(public int $value)
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw new InvalidArgumentException("Purity {$value} out of range");
        }
    }

    /** از عیار متعارف بازار: 750, 995, 999 */
    public static function fromPpt(int $ppt): self
    {
        return new self($ppt * 10);
    }

    /** از عیار با اعشار: 995.5 */
    public static function fromDecimal(float $ppt): self
    {
        return new self((int) round($ppt * 10));
    }

    public static function pure(): self { return new self(10_000); }

    /** نمایش متعارف: 995 یا 995.5 */
    public function toPpt(): string
    {
        return $this->value % 10 === 0
            ? (string) intdiv($this->value, 10)
            : bcdiv((string) $this->value, '10', 1);
    }

    public function isAtLeast(Purity $o): bool { return $this->value >= $o->value; }
}
```

<div dir="rtl">

</div>

```php
/** وزن خالص — نوع مجزا از Weight تا با وزن ناخالص اشتباه نشود */
final readonly class FineWeight
{
    private function __construct(public int $milligrams) { /* ... */ }

    public static function fromMilligrams(int $mg): self { return new self($mg); }
    public static function fromGrams(float $g): self     { return new self((int) round($g * 1000)); }

    /** محاسبه از وزن ناخالص و عیار — تنها راه مجاز */
    public static function calculate(Weight $gross, Purity $purity): self
    {
        // fine = floor(gross × purity / 10000)
        // گِردکردن به سمت پایین: سیستم هرگز طلایی که ندارد ثبت نکند
        $result = bcdiv(
            bcmul((string) $gross->milligrams, (string) $purity->value),
            '10000',
            0,               // scale = 0 ► حذف اعشار
        );

        return new self((int) $result);
    }

    /** باقیمانده گِردکردن — برای ثبت در حساب ROUNDING */
    public static function roundingRemainder(Weight $gross, Purity $purity): int
    {
        $exact = bcmul((string) $gross->milligrams, (string) $purity->value);
        $floor = bcmul(bcdiv($exact, '10000', 0), '10000');
        return (int) bcdiv(bcsub($exact, $floor), '10000', 0);
    }
}
```

<div dir="rtl">

</div>

```php
/** مبلغ ریالی */
final readonly class Rial
{
    private function __construct(public int $amount) {}

    public static function fromRial(int $r): self { return new self($r); }
    public static function zero(): self           { return new self(0); }

    public function plus(Rial $o): self  { return new self($this->amount + $o->amount); }
    public function minus(Rial $o): self { return new self($this->amount - $o->amount); }
    public function negate(): self       { return new self(-$this->amount); }

    /** ضرب در نرخ بر حسب basis point ×10 (یک‌صدهزارم) */
    public function percentage(int $rateX100k): self
    {
        return new self((int) bcdiv(
            bcmul((string) $this->amount, (string) $rateX100k),
            '100000',
            0,
        ));
    }

    public function format(): string
    {
        return number_format($this->amount) . ' ریال';
    }
}
```

<div dir="rtl">

---

## ۸.۲ محاسبه ارزش معامله

</div>

```php
namespace App\Modules\Shared\Calculation;

final readonly class TradeValueCalculator
{
    /**
     * محاسبه کامل ارزش یک معامله.
     * تنها نقطه‌ای که این محاسبه انجام می‌شود.
     */
    public function calculate(
        FineWeight $fineWeight,
        PricePerFineGram $price,
        FeeSchedule $fees,
        TaxSchedule $taxes,
        Side $side,
    ): TradeValuation {
        // ۱) مبلغ ناخالص = وزن خالص (گرم) × قیمت هر گرم
        //    fine_mg / 1000 × price  =  fine_mg × price / 1000
        $gross = Rial::fromRial((int) bcdiv(
            bcmul((string) $fineWeight->milligrams, (string) $price->rial),
            '1000',
            0,
        ));

        // ۲) کارمزد
        $fee = $gross->percentage($fees->rateFor($side));
        $fee = $this->applyBounds($fee, $fees->minAmount, $fees->maxAmount);

        // ۳) مالیات (روی کارمزد یا روی مبلغ، طبق مقررات)
        $tax = $taxes->calculate($gross, $fee);

        // ۴) مبلغ خالص
        $net = $side === Side::BUY
            ? $gross->plus($fee)->plus($tax)      // خریدار پرداخت می‌کند
            : $gross->minus($fee)->minus($tax);   // فروشنده دریافت می‌کند

        return new TradeValuation(
            fineWeight: $fineWeight,
            pricePerFineGram: $price,
            grossAmount: $gross,
            feeAmount: $fee,
            taxAmount: $tax,
            netAmount: $net,
        );
    }

    private function applyBounds(Rial $fee, ?Rial $min, ?Rial $max): Rial
    {
        if ($min !== null && $fee->amount < $min->amount) return $min;
        if ($max !== null && $fee->amount > $max->amount) return $max;
        return $fee;
    }
}
```

<div dir="rtl">

### مثال عددی کامل

</div>

```
ورودی:
  وزن ناخالص      : 250.000 گرم  ► 250,000 mg
  عیار            : 995          ► 9950
  قیمت هر گرم خالص: 78,480,000 ریال
  کارمزد خریدار   : 0.15%        ► 150 (یک‌صدهزارم)
  کارمزد فروشنده  : 0.10%        ► 100
  مالیات          : 0            (طبق مقررات فعلی)

محاسبه:
  ۱) وزن خالص
     = floor(250,000 × 9,950 / 10,000)
     = floor(2,487,500,000 / 10,000)
     = 248,750 mg
     = 248.750 گرم

  ۲) مبلغ ناخالص
     = 248,750 × 78,480,000 / 1,000
     = 19,521,900,000,000 / 1,000
     = 19,521,900,000 ریال

  ۳) کارمزد خریدار
     = 19,521,900,000 × 150 / 100,000
     = 29,282,850 ریال

  ۴) کارمزد فروشنده
     = 19,521,900,000 × 100 / 100,000
     = 19,521,900 ریال

خروجی:
  ┌──────────────────────────────────────────────┐
  │ وزن ناخالص      250.000 g                    │
  │ عیار            995                          │
  │ وزن خالص        248.750 g                    │
  │ قیمت هر گرم     78,480,000 ریال              │
  │ ───────────────────────────────────────────  │
  │ مبلغ ناخالص     19,521,900,000 ریال          │
  │ کارمزد          29,282,850 ریال              │
  │ مالیات          0 ریال                        │
  │ ───────────────────────────────────────────  │
  │ پرداختی خریدار  19,551,182,850 ریال          │
  │ دریافتی فروشنده 19,502,378,100 ریال          │
  │ درآمد سامانه    48,804,750 ریال              │
  └──────────────────────────────────────────────┘

بررسی بقای جرم:
  19,551,182,850 = 19,502,378,100 + 48,804,750  ✅
```

<div dir="rtl">

---

## ۸.۳ قواعد گِردکردن — الزامی و یکسان

</div>

```
┌──────────────────────┬──────────┬────────────────────────────────┐
│ محاسبه               │ جهت      │ دلیل                           │
├──────────────────────┼──────────┼────────────────────────────────┤
│ وزن خالص             │ FLOOR    │ سیستم طلایی که ندارد ثبت نکند   │
│ مبلغ ناخالص          │ FLOOR    │ محافظه‌کارانه                   │
│ کارمزد               │ CEIL     │ به نفع سامانه، شفاف اعلام‌شده   │
│ مالیات               │ CEIL     │ طبق الزام مالیاتی               │
│ مبلغ خالص فروشنده    │ FLOOR    │ محافظه‌کارانه                   │
│ مبلغ خالص خریدار     │ CEIL     │ محافظه‌کارانه                   │
│ تبدیل واحد نمایشی    │ ROUND    │ فقط نمایش، بدون اثر مالی        │
└──────────────────────┴──────────┴────────────────────────────────┘

هر باقیمانده گِردکردن ► حساب سیستمی ROUNDING_DIFFERENCE
```

<div dir="rtl">

**چرا این مهم است:**
اگر کارمزد `FLOOR` و مبلغ خالص هم `FLOOR` باشد، مجموع تراز نمی‌شود و
بقای جرم نقض می‌شود. جهت گِردکردن باید طوری انتخاب شود که
`gross = net_seller + fee + tax` همیشه دقیقاً برقرار باشد.

**پیاده‌سازی امن:**

</div>

```php
// ✅ روش درست — یکی را محاسبه، بقیه را از تفریق
$gross = $this->computeGross($fine, $price);       // FLOOR
$fee   = $this->computeFee($gross, $rate);         // CEIL
$tax   = $this->computeTax($gross, $fee);          // CEIL
$netSeller = $gross->minus($fee)->minus($tax);     // ◄ تفریق، نه محاسبه مستقل

// حالا تضمینی است: gross == netSeller + fee + tax
```

<div dir="rtl">

---

## ۸.۴ جدول کارمزد

</div>

```sql
CREATE TABLE fee_schedules (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code              VARCHAR(50) NOT NULL UNIQUE,
  name              VARCHAR(191) NOT NULL,
  applies_to        ENUM('ALL','ORDER_BOOK','OTC','RFQ') NOT NULL,
  side              ENUM('BUY','SELL','BOTH') NOT NULL,
  role              ENUM('MAKER','TAKER','BOTH') NOT NULL,

  -- نرخ بر حسب یک‌صدهزارم: 150 = 0.15%
  rate_x100k        INT UNSIGNED NOT NULL,
  min_amount_rial   BIGINT UNSIGNED NULL,
  max_amount_rial   BIGINT UNSIGNED NULL,

  -- پلکانی بر اساس حجم ماهانه
  tier_from_mg      BIGINT UNSIGNED NULL,
  tier_to_mg        BIGINT UNSIGNED NULL,

  effective_from    TIMESTAMP NOT NULL,
  effective_until   TIMESTAMP NULL,
  status            ENUM('ACTIVE','INACTIVE') NOT NULL
) ENGINE=InnoDB;
```

<div dir="rtl">

### کارمزد پلکانی

</div>

```
حجم ماهانه (طلای خالص)      نرخ Taker    نرخ Maker
────────────────────────────────────────────────────
0 – 10 kg                    0.20%        0.15%
10 – 50 kg                   0.15%        0.10%
50 – 200 kg                  0.12%        0.08%
200 kg +                     0.10%        0.05%

· Maker همیشه نرخ کمتر ► تشویق به تأمین نقدشوندگی
· محاسبه حجم بر اساس ۳۰ روز گذشته
· تغییر پله در ابتدای هر ماه اعمال می‌شود
```

<div dir="rtl">

> ⚠️ نرخ‌های بالا نمونه هستند. نرخ‌گذاری واقعی باید با در نظر گرفتن
> اقتصاد محصول، رقابت و الزامات مقرراتی تعیین شود.

---

## ۸.۵ تبدیل واحد

</div>

```php
final class UnitConverter
{
    public const MG_PER_GRAM    = 1_000;
    public const MG_PER_MESGHAL = 4_608;     // 4.6083 g، گِردشده
    public const MG_PER_OUNCE   = 31_103;    // 31.1034768 g

    // برای دقت بالا از مقادیر مقیاس‌شده استفاده می‌شود
    public const MESGHAL_MG_X10000 = 46_083_000;
    public const OUNCE_MG_X10000   = 311_034_768;

    public static function gramsToMesghal(string $grams): string
    {
        return bcdiv(bcmul($grams, '10000'), '46083', 4);
    }

    public static function mesghalToGrams(string $mesghal): string
    {
        return bcdiv(bcmul($mesghal, '46083'), '10000', 3);
    }
}
```

<div dir="rtl">

> ⚠️ تبدیل واحد **فقط برای نمایش**. هیچ محاسبه مالی نباید از مقدار
> تبدیل‌شده استفاده کند. مبنا همیشه میلی‌گرم است.

---

## ۸.۶ محاسبه سود و زیان

### روش میانگین موزون (Weighted Average Cost)

</div>

```
موجودی اولیه:  100 g @ میانگین 75,000,000
خرید:          200 g @ 78,000,000

میانگین جدید = (100 × 75,000,000 + 200 × 78,000,000) / 300
             = (7,500,000,000 + 15,600,000,000) / 300
             = 23,100,000,000 / 300
             = 77,000,000 ریال/گرم

فروش 150 g @ 80,000,000:
   درآمد = 150 × 80,000,000 = 12,000,000,000
   بهای تمام‌شده = 150 × 77,000,000 = 11,550,000,000
   ─────────────────────────────────────────────
   سود ناخالص = 450,000,000 ریال
   منهای کارمزد ► سود خالص

موجودی باقیمانده: 150 g @ 77,000,000
```

<div dir="rtl">

**پیاده‌سازی:**

</div>

```sql
CREATE TABLE inventory_cost_basis (
  organization_id     BIGINT UNSIGNED PRIMARY KEY,
  quantity_fine_mg    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_cost_rial     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  avg_cost_per_gram   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_updated_at     TIMESTAMP NOT NULL,
  version             BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;
```

<div dir="rtl">

</div>

```php
public function recordPurchase(int $orgId, FineWeight $qty, Rial $totalCost): void
{
    DB::transaction(function () use ($orgId, $qty, $totalCost) {
        $basis = InventoryCostBasis::lockForUpdate()->findOrNew($orgId);

        $newQty  = $basis->quantity_fine_mg + $qty->milligrams;
        $newCost = $basis->total_cost_rial + $totalCost->amount;

        $basis->fill([
            'quantity_fine_mg'  => $newQty,
            'total_cost_rial'   => $newCost,
            'avg_cost_per_gram' => $newQty > 0
                ? (int) bcdiv(bcmul((string) $newCost, '1000'), (string) $newQty, 0)
                : 0,
        ])->save();
    });
}

public function recordSale(int $orgId, FineWeight $qty, Rial $revenue): ProfitResult
{
    return DB::transaction(function () use ($orgId, $qty, $revenue) {
        $basis = InventoryCostBasis::lockForUpdate()->findOrFail($orgId);

        $costOfSale = Rial::fromRial((int) bcdiv(
            bcmul((string) $qty->milligrams, (string) $basis->avg_cost_per_gram),
            '1000',
            0,
        ));

        $basis->decrement('quantity_fine_mg', $qty->milligrams);
        $basis->decrement('total_cost_rial', $costOfSale->amount);
        // avg_cost_per_gram تغییر نمی‌کند در فروش

        return new ProfitResult(
            revenue: $revenue,
            costOfGoodsSold: $costOfSale,
            grossProfit: $revenue->minus($costOfSale),
        );
    });
}
```

<div dir="rtl">

### سود تجدید ارزیابی (Unrealized P&L)

</div>

```
موجودی فعلی    : 150 g
میانگین بها    : 77,000,000
قیمت روز       : 78,480,000

ارزش دفتری     = 150 × 77,000,000 = 11,550,000,000
ارزش بازار     = 150 × 78,480,000 = 11,772,000,000
────────────────────────────────────────────────
سود تحقق‌نیافته = 222,000,000 ریال

⚠️ این عدد فقط اطلاعاتی است و در دفتر کل ثبت نمی‌شود.
   ثبت آن نیازمند سیاست حسابداری مشخص است.
```

<div dir="rtl">

---

## ۸.۷ تست‌های الزامی موتور محاسبات

</div>

```php
/** @test */
public function fine_weight_calculation_is_exact(): void
{
    $cases = [
        // [gross_mg, purity_x10, expected_fine_mg]
        [100_000,  10_000, 100_000],   // خالص
        [100_000,   7_500,  75_000],   // عیار 750
        [127_420,   7_500,  95_565],   // مثال سند
        [250_000,   9_950, 248_750],   // مثال سند
        [1,         9_999,       0],   // گِردکردن به پایین
        [3,         3_333,       0],   // 0.99999 ► 0
    ];

    foreach ($cases as [$gross, $purity, $expected]) {
        $this->assertSame($expected, FineWeight::calculate(
            Weight::fromMilligrams($gross),
            new Purity($purity),
        )->milligrams);
    }
}

/** @test */
public function trade_amounts_always_balance(): void
{
    // property-based: هزار ترکیب تصادفی
    for ($i = 0; $i < 1000; $i++) {
        $valuation = $this->calculator->calculate(
            FineWeight::fromMilligrams(random_int(1000, 100_000_000)),
            PricePerFineGram::fromRial(random_int(1_000_000, 200_000_000)),
            $this->randomFeeSchedule(),
            $this->randomTaxSchedule(),
            Side::BUY,
        );

        $this->assertSame(
            $valuation->grossAmount->amount,
            $valuation->netSellerAmount->amount
                + $valuation->feeAmount->amount
                + $valuation->taxAmount->amount,
            'Trade amounts do not balance',
        );
    }
}

/** @test */
public function no_float_arithmetic_in_financial_paths(): void
{
    // static analysis: هیچ float یا double در مسیرهای مالی
    $this->assertNoFloatUsageIn([
        'app/Modules/Ledger',
        'app/Modules/Settlement',
        'app/Modules/Shared/Calculation',
    ]);
}
```

</div>
