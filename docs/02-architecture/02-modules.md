<div dir="rtl">

# ۲. مرزبندی ماژول‌ها

## ۲.۱ ساختار پوشه

</div>

```
app/
├── Modules/
│   ├── Shared/                  ← بدون وابستگی به هیچ ماژول دیگر
│   │   ├── ValueObjects/
│   │   │   ├── Weight.php           (میلی‌گرم)
│   │   │   ├── Purity.php           (هزارم)
│   │   │   ├── FineWeight.php
│   │   │   ├── Rial.php
│   │   │   └── PricePerGram.php
│   │   ├── Audit/
│   │   ├── Idempotency/
│   │   ├── Money/
│   │   └── Contracts/
│   │
│   ├── Identity/
│   │   ├── Domain/              ← Entity, VO, Domain Service
│   │   ├── Application/         ← Use Case / Service
│   │   ├── Infrastructure/      ← Eloquent Model, Repository
│   │   ├── Http/                ← Controller, Request, Resource
│   │   ├── Events/              ← Domain Events (عمومی)
│   │   ├── Listeners/
│   │   ├── Database/            ← Migration, Factory, Seeder
│   │   ├── Tests/
│   │   └── IdentityServiceProvider.php
│   │
│   ├── Kyc/
│   ├── Custody/
│   ├── Ledger/
│   ├── Trading/
│   ├── Settlement/
│   ├── Pricing/
│   ├── Risk/
│   ├── Accounting/
│   ├── Dispute/
│   ├── Notification/
│   └── Reporting/
│
└── Providers/
```

<div dir="rtl">

---

## ۲.۲ گراف وابستگی مجاز

</div>

```
                        ┌──────────┐
                        │  Shared  │  ◄── همه می‌توانند وابسته باشند
                        └──────────┘

  ┌──────────┐
  │ Identity │  ◄── فقط Shared
  └────┬─────┘
       │
  ┌────▼─────┐     ┌──────────┐     ┌──────────┐
  │   Kyc    │     │ Pricing  │     │ Custody  │
  └──────────┘     └──────────┘     └────┬─────┘
                                          │
  ┌──────────────────────────────────────▼─────┐
  │                  Ledger                     │
  │  ◄── Identity, Custody, Shared              │
  └────┬────────────────────────────────────────┘
       │
  ┌────▼─────┐
  │   Risk   │  ◄── Identity, Ledger
  └────┬─────┘
       │
  ┌────▼─────────┐
  │   Trading    │  ◄── Identity, Ledger, Risk, Pricing, Custody
  └────┬─────────┘
       │
  ┌────▼─────────┐
  │  Settlement  │  ◄── Trading, Ledger, Custody
  └────┬─────────┘
       │
  ┌────▼─────────┐   ┌──────────┐
  │  Accounting  │   │ Dispute  │  ◄── Trading, Settlement
  └──────────────┘   └──────────┘

  ┌──────────────┐   ┌──────────┐
  │ Notification │   │Reporting │  ◄── فقط Event، بدون وابستگی مستقیم
  └──────────────┘   └──────────┘
```

<div dir="rtl">

**قاعده:** وابستگی فقط **رو به بالا** در این گراف. هیچ وابستگی معکوس یا
دوری مجاز نیست. اگر ماژول پایین‌تر نیاز به اطلاع از ماژول بالاتر داشت،
از **Domain Event** استفاده می‌شود.

### اعمال با ابزار (deptrac)

</div>

```yaml
# deptrac.yaml
parameters:
  paths: [./app/Modules]
  layers:
    - name: Shared
      collectors: [{ type: directory, value: app/Modules/Shared/.* }]
    - name: Identity
      collectors: [{ type: directory, value: app/Modules/Identity/.* }]
    - name: Ledger
      collectors: [{ type: directory, value: app/Modules/Ledger/.* }]
    - name: Trading
      collectors: [{ type: directory, value: app/Modules/Trading/.* }]
    # ...
  ruleset:
    Shared: ~
    Identity: [Shared]
    Kyc: [Shared, Identity]
    Custody: [Shared, Identity]
    Ledger: [Shared, Identity, Custody]
    Risk: [Shared, Identity, Ledger]
    Pricing: [Shared]
    Trading: [Shared, Identity, Ledger, Risk, Pricing, Custody]
    Settlement: [Shared, Identity, Ledger, Trading, Custody]
    Accounting: [Shared, Identity, Ledger, Trading, Settlement]
    Dispute: [Shared, Identity, Trading, Settlement]
    Notification: [Shared]
    Reporting: [Shared]
```

<div dir="rtl">

اجرای `vendor/bin/deptrac` در CI؛ شکست build در صورت نقض.

---

## ۲.۳ قرارداد عمومی هر ماژول

هر ماژول دقیقاً یک فایل `Contracts/` دارد که سطح عمومی آن را تعریف می‌کند.
سایر ماژول‌ها **فقط** از این اینترفیس‌ها استفاده می‌کنند.

### مثال: Ledger

</div>

```php
namespace App\Modules\Ledger\Contracts;

use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;

interface GoldLedgerInterface
{
    /** مانده قابل استفاده */
    public function availableBalance(int $organizationId): FineWeight;

    /** مانده قفل‌شده */
    public function reservedBalance(int $organizationId): FineWeight;

    /**
     * قفل کردن مقدار مشخص. اگر موجودی کافی نباشد،
     * InsufficientBalanceException پرتاب می‌شود.
     * باید داخل تراکنش فراخوانی شود.
     */
    public function reserve(
        int $organizationId,
        FineWeight $amount,
        LedgerReference $ref,
    ): LedgerEntryId;

    /** آزادسازی قفل بدون انتقال */
    public function release(LedgerEntryId $reservationId): void;

    /**
     * انتقال اتمیک بین دو سازمان.
     * مقدار باید از قبل reserve شده باشد.
     */
    public function transfer(
        int $fromOrganizationId,
        int $toOrganizationId,
        FineWeight $amount,
        LedgerReference $ref,
    ): TransferResult;

    /** ثبت معکوس یک ورودی — تنها راه اصلاح */
    public function reverse(LedgerEntryId $entryId, string $reason): LedgerEntryId;

    /** بازسازی مانده از روی تمام ورودی‌ها (برای مغایرت‌گیری) */
    public function rebuildBalance(int $organizationId): FineWeight;
}
```

<div dir="rtl">

### مثال: Risk

</div>

```php
namespace App\Modules\Risk\Contracts;

interface RiskGuardInterface
{
    /**
     * بررسی می‌کند آیا سازمان مجاز به انجام این معامله است.
     * در صورت عدم مجوز، LimitExceededException با ذکر
     * دقیق سقف نقض‌شده پرتاب می‌شود.
     */
    public function assertTradeAllowed(TradeIntent $intent): void;

    /** بدون پرتاب استثنا — برای نمایش در UI */
    public function evaluate(TradeIntent $intent): RiskDecision;

    public function remainingDailyLimit(int $organizationId): FineWeight;
}
```

<div dir="rtl">

### مثال: Custody

</div>

```php
namespace App\Modules\Custody\Contracts;

interface GoldLotRepositoryInterface
{
    public function findById(GoldLotId $id): ?GoldLotSnapshot;

    /** lotهای در دسترس یک مالک با شرط عیار */
    public function availableForOwner(
        int $organizationId,
        ?Purity $minPurity = null,
    ): GoldLotCollection;

    /** انتقال مالکیت بدون تغییر custodian */
    public function transferOwnership(
        GoldLotId $lotId,
        int $newOwnerOrganizationId,
        TransferReason $reason,
    ): void;
}
```

<div dir="rtl">

> نکته: خروجی‌ها `Snapshot` / DTO هستند، نه Eloquent Model. این کار مانع
> از نشت جزئیات پیاده‌سازی به ماژول‌های دیگر می‌شود.

---

## ۲.۴ رویدادهای دامنه (Domain Events)

رویدادها **قرارداد عمومی** هستند و تغییرشان breaking change محسوب می‌شود.

### قواعد نام‌گذاری

</div>

```
<Module>\Events\<Aggregate><PastTenseVerb>

مثال:
  Trading\Events\OrderPlaced
  Trading\Events\OrderCancelled
  Trading\Events\TradeExecuted
  Ledger\Events\BalanceReserved
  Ledger\Events\TransferCompleted
  Settlement\Events\SettlementCompleted
  Custody\Events\LotSplit
  Identity\Events\OrganizationActivated
```

<div dir="rtl">

### قواعد محتوا

</div>

```php
// ✅ رویداد فقط شناسه و داده تغییرناپذیر حمل می‌کند
final readonly class TradeExecuted
{
    public function __construct(
        public int $tradeId,
        public int $buyerOrganizationId,
        public int $sellerOrganizationId,
        public int $fineWeightMg,
        public int $pricePerGramRial,
        public int $grossAmountRial,
        public string $occurredAt,   // ISO-8601
    ) {}
}

// ❌ هرگز Eloquent Model داخل رویداد
final class TradeExecuted
{
    public function __construct(public Trade $trade) {}  // بد
}
```

<div dir="rtl">

دلیل: رویداد ممکن است ساعت‌ها بعد در صف پردازش شود؛ مدل ممکن است تا آن
موقع تغییر کرده باشد یا serialize نشود.

### جدول رویدادهای اصلی

| رویداد | منتشرکننده | شنوندگان |
|---|---|---|
| `OrganizationActivated` | Identity | Ledger (ساخت حساب)، Risk (پروفایل)، Notification |
| `KycDocumentUploaded` | Kyc | Notification |
| `OrderPlaced` | Trading | Broadcasting، Audit |
| `OrderCancelled` | Trading | Ledger (release)، Broadcasting |
| `TradeExecuted` | Trading | Settlement، Accounting، Risk، Reputation، Notification، Broadcasting |
| `BalanceReserved` | Ledger | Audit |
| `TransferCompleted` | Ledger | Accounting، Audit |
| `SettlementCompleted` | Settlement | Accounting، Reputation، Notification |
| `SettlementOverdue` | Settlement | Risk، Notification، Compliance |
| `LotSplit` / `LotMerged` | Custody | Audit، Ledger (اگر مانده تغییر کند) |
| `DisputeOpened` | Dispute | Ledger (قفل)، Notification، Compliance |
| `AmlFlagRaised` | Risk | Compliance، Notification |

---

## ۲.۵ مدیریت تراکنش بین ماژول‌ها

**قاعده:** تراکنش متعلق به **Use Case** است، نه به Repository یا Service.

</div>

```php
// Trading\Application\PlaceOrderHandler

public function handle(PlaceOrderCommand $cmd): OrderResult
{
    // ۱. بررسی‌های خارج از تراکنش (بدون تغییر داده)
    $this->riskGuard->assertTradeAllowed($cmd->toIntent());

    // ۲. تراکنش کوتاه و اتمیک
    $result = DB::transaction(function () use ($cmd) {
        $requirement = $this->calculator->requirementFor($cmd);

        $reservationId = $cmd->side === Side::BUY
            ? $this->rialLedger->reserve($cmd->organizationId, $requirement->rial, $ref)
            : $this->goldLedger->reserve($cmd->organizationId, $requirement->gold, $ref);

        $order = $this->orders->create($cmd, $reservationId);

        $matches = $this->matchingEngine->match($order);

        return new OrderResult($order, $matches);
    }, attempts: 3);   // retry روی deadlock

    // ۳. رویدادها پس از commit
    event(new OrderPlaced(...));
    foreach ($result->matches as $trade) {
        event(new TradeExecuted(...));
    }

    return $result;
}
```

<div rtl="rtl">
</div>

<div dir="rtl">

**ممنوعیت‌ها:**
- ❌ تراکنش تودرتو با معنای متفاوت
- ❌ فراخوانی HTTP خارجی داخل تراکنش
- ❌ `sleep`، صف، یا اعلان داخل تراکنش
- ❌ تراکنش با بیش از ~۵۰۰ms طول

---

## ۲.۶ ثبت ماژول

هر ماژول یک `ServiceProvider` دارد:

</div>

```php
namespace App\Modules\Ledger;

class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GoldLedgerInterface::class, GoldLedgerService::class);
        $this->app->bind(RialLedgerInterface::class, RialLedgerService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');

        Event::listen(OrganizationActivated::class, CreateLedgerAccounts::class);
    }
}
```

<div dir="rtl">

ثبت خودکار در `bootstrap/providers.php` یا با کشف مبتنی بر پوشه.

---

## ۲.۷ تست به تفکیک ماژول

</div>

```
app/Modules/Ledger/Tests/
├── Unit/
│   ├── FineWeightTest.php
│   └── BalanceCalculatorTest.php
├── Feature/
│   ├── ReserveAndReleaseTest.php
│   ├── TransferTest.php
│   ├── ReversalTest.php
│   └── ConcurrentTransferTest.php    ← تست همزمانی الزامی
└── Property/
    └── LedgerInvariantTest.php       ← تست ویژگی‌محور
```

<div dir="rtl">

**ثابت‌های (Invariants) الزامی برای تست ویژگی‌محور Ledger:**

</div>

```
۱. مجموع تمام entryهای یک حساب == مانده ذخیره‌شده
۲. available + reserved == total
۳. available هرگز منفی نیست
۴. مجموع کل طلای سیستم پس از هر transfer ثابت است  ◄── مهم‌ترین
۵. هر reverse دقیقاً یک entry با علامت مخالف می‌سازد
۶. هیچ entry حذف یا ویرایش نمی‌شود
```

<div dir="rtl">

تست ۴ (بقای جرم) مهم‌ترین محافظ در برابر باگ‌های مالی است و باید در
هر build اجرا شود.

</div>
