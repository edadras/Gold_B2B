<div dir="rtl">

# ۱. ساختار پروژه Laravel

## ۱.۱ نسخه‌ها

| مؤلفه | نسخه |
|---|---|
| PHP | 8.3+ |
| Laravel | 11.x |
| MySQL | 8.0.16+ (برای `CHECK constraint`) |
| Redis | 7.x |
| Laravel Reverb | 1.x |
| Laravel Sanctum | 4.x |
| Laravel Horizon | 5.x |
| Filament (پنل ادمین) | 3.x |

---

## ۱.۲ درخت پوشه

</div>

```
Gold_B2B/
├── app/
│   ├── Modules/                    ◄── هسته کد دامنه
│   │   ├── Shared/
│   │   ├── Identity/
│   │   ├── Kyc/
│   │   ├── Custody/
│   │   ├── Ledger/
│   │   ├── Trading/
│   │   ├── Settlement/
│   │   ├── Pricing/
│   │   ├── Risk/
│   │   ├── Accounting/
│   │   ├── Counterparty/
│   │   ├── Dispute/
│   │   ├── Reputation/
│   │   ├── Notification/
│   │   └── Reporting/
│   │
│   ├── Http/
│   │   ├── Middleware/             ◄── middleware سراسری
│   │   └── Kernel.php
│   │
│   ├── Console/
│   │   └── Commands/               ◄── دستورات عملیاتی
│   │
│   ├── Providers/
│   ├── Exceptions/
│   └── Filament/                   ◄── پنل ادمین
│
├── bootstrap/
├── config/
├── database/
│   ├── migrations/                 ◄── فقط migrationهای عرضی
│   └── seeders/
│
├── mobile/                         ◄── پروژه Flutter
├── resources/
│   ├── js/                         ◄── Vue 3 (پنل وب)
│   ├── css/
│   ├── views/
│   └── lang/fa/
│
├── routes/
│   ├── api.php                     ◄── فقط include ماژول‌ها
│   ├── web.php
│   ├── channels.php
│   └── console.php
│
├── tests/
│   ├── Feature/
│   ├── Unit/
│   └── Integration/
│
├── docs/                           ◄── همین مستندات
├── deptrac.yaml
├── phpstan.neon
├── pint.json
└── composer.json
```

<div dir="rtl">

---

## ۱.۳ ساختار داخلی یک ماژول

نمونه کامل برای `Ledger`:

</div>

```
app/Modules/Ledger/
│
├── Contracts/                       ◄── سطح عمومی ماژول
│   ├── GoldLedgerInterface.php
│   ├── RialLedgerInterface.php
│   └── Dto/
│       ├── LedgerReference.php
│       ├── TransferResult.php
│       └── BalanceSnapshot.php
│
├── Domain/                          ◄── منطق خالص، بدون Laravel
│   ├── Entities/
│   │   ├── LedgerEntry.php
│   │   └── LedgerAccount.php
│   ├── ValueObjects/
│   │   ├── EntryType.php
│   │   ├── Bucket.php
│   │   └── TransactionGroup.php
│   ├── Services/
│   │   ├── BalanceCalculator.php
│   │   └── HashChainBuilder.php
│   └── Exceptions/
│       ├── InsufficientBalanceException.php
│       ├── AlreadyReversedException.php
│       └── UnbalancedTransactionException.php
│
├── Application/                     ◄── Use Case
│   ├── GoldLedgerService.php
│   ├── RialLedgerService.php
│   ├── ReconciliationService.php
│   └── Commands/
│       ├── ReserveCommand.php
│       └── TransferCommand.php
│
├── Infrastructure/                  ◄── Eloquent، دیتابیس
│   ├── Models/
│   │   ├── LedgerAccountModel.php
│   │   ├── LedgerEntryModel.php
│   │   └── LedgerBalanceModel.php
│   ├── Repositories/
│   │   └── EloquentLedgerRepository.php
│   └── Locking/
│       └── AccountLocker.php
│
├── Http/
│   ├── Controllers/
│   │   ├── BalanceController.php
│   │   └── LedgerController.php
│   ├── Requests/
│   │   └── LedgerQueryRequest.php
│   ├── Resources/
│   │   ├── BalanceResource.php
│   │   └── LedgerEntryResource.php
│   └── routes.php
│
├── Events/
│   ├── BalanceReserved.php
│   ├── TransferCompleted.php
│   └── BalanceDiscrepancyDetected.php
│
├── Listeners/
│   └── CreateLedgerAccountsOnActivation.php
│
├── Console/
│   ├── ReconcileLedgerCommand.php
│   └── BuildSnapshotCommand.php
│
├── Database/
│   ├── Migrations/
│   ├── Factories/
│   └── Seeders/
│
├── Tests/
│   ├── Unit/
│   ├── Feature/
│   └── Property/
│
└── LedgerServiceProvider.php
```

<div dir="rtl">

---

## ۱.۴ ثبت ماژول

</div>

```php
// app/Modules/Ledger/LedgerServiceProvider.php

namespace App\Modules\Ledger;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\{Route, Event};

class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            Contracts\GoldLedgerInterface::class,
            Application\GoldLedgerService::class,
        );

        $this->app->bind(
            Contracts\RialLedgerInterface::class,
            Application\RialLedgerService::class,
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Database/Migrations');

        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'organization.active'])
            ->group(__DIR__ . '/Http/routes.php');

        Event::listen(
            \App\Modules\Identity\Events\OrganizationActivated::class,
            Listeners\CreateLedgerAccountsOnActivation::class,
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\ReconcileLedgerCommand::class,
                Console\BuildSnapshotCommand::class,
            ]);
        }
    }
}
```

<div dir="rtl">

### کشف خودکار ماژول‌ها

</div>

```php
// bootstrap/providers.php

$modules = ['Shared','Identity','Kyc','Custody','Ledger','Trading',
            'Settlement','Pricing','Risk','Accounting','Counterparty',
            'Dispute','Reputation','Notification','Reporting'];

return array_merge(
    [App\Providers\AppServiceProvider::class],
    array_map(
        fn (string $m) => "App\\Modules\\{$m}\\{$m}ServiceProvider",
        $modules,
    ),
);
```

<div dir="rtl">

---

## ۱.۵ تفکیک لایه‌ها

</div>

```
┌────────────────────────────────────────────────────────┐
│  Http/                                                 │
│  · Controller — فقط دریافت درخواست و بازگرداندن پاسخ   │
│  · Request    — اعتبارسنجی ورودی                        │
│  · Resource   — قالب‌بندی خروجی                          │
│  ⛔ بدون منطق کسب‌وکار                                   │
└──────────────────────┬─────────────────────────────────┘
                       │
┌──────────────────────▼─────────────────────────────────┐
│  Application/                                          │
│  · Service / Handler — هماهنگی Use Case                │
│  · مدیریت تراکنش                                        │
│  · انتشار رویداد                                        │
│  ⛔ بدون کوئری مستقیم SQL                                │
└──────────────────────┬─────────────────────────────────┘
                       │
┌──────────────────────▼─────────────────────────────────┐
│  Domain/                                               │
│  · Entity, Value Object, Domain Service                │
│  · قواعد کسب‌وکار خالص                                  │
│  ⛔ بدون Eloquent، بدون Facade، بدون DB                  │
│  ✅ قابل تست بدون دیتابیس                                │
└──────────────────────┬─────────────────────────────────┘
                       │
┌──────────────────────▼─────────────────────────────────┐
│  Infrastructure/                                       │
│  · Eloquent Model                                      │
│  · Repository                                          │
│  · Cache, Lock, External API                           │
└────────────────────────────────────────────────────────┘
```

<div dir="rtl">

### مثال درست

</div>

```php
// ❌ اشتباه — منطق در Controller
class OrderController
{
    public function store(Request $request)
    {
        $balance = DB::table('ledger_balances')->where(...)->value('balance');
        if ($balance < $request->quantity * $request->price) {
            return response()->json(['error' => 'Insufficient'], 422);
        }
        DB::table('orders')->insert([...]);
        // ...
    }
}
```

<div dir="rtl">

</div>

```php
// ✅ درست
final class OrderController
{
    public function __construct(
        private readonly PlaceOrderHandler $handler,
    ) {}

    public function store(PlaceOrderRequest $request): OrderResource
    {
        $result = $this->handler->handle(
            PlaceOrderCommand::fromRequest($request, auth()->user()),
        );

        return new OrderResource($result->order);
    }
}
```

<div dir="rtl">

استثناها (`InsufficientBalanceException` و …) در `Exceptions/Handler.php`
به پاسخ HTTP مناسب تبدیل می‌شوند.

---

## ۱.۶ Middleware سراسری

</div>

```php
// bootstrap/app.php  (Laravel 11)

->withMiddleware(function (Middleware $middleware) {
    $middleware->api(prepend: [
        \App\Http\Middleware\AssignRequestId::class,
        \App\Http\Middleware\ForceJsonResponse::class,
    ]);

    $middleware->api(append: [
        \App\Http\Middleware\RecordAuditLog::class,
    ]);

    $middleware->alias([
        'organization.active' => \App\Http\Middleware\EnsureOrganizationActive::class,
        'idempotency'         => \App\Http\Middleware\HandleIdempotency::class,
        'transaction.sign'    => \App\Http\Middleware\RequireTransactionSignature::class,
        'market.open'         => \App\Http\Middleware\EnsureMarketOpen::class,
        'permission'          => \App\Http\Middleware\CheckPermission::class,
    ]);
})
```

<div dir="rtl">

### نمونه: Idempotency Middleware

</div>

```php
namespace App\Http\Middleware;

final class HandleIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null) {
            return response()->json([
                'error' => [
                    'code' => 'IDEMPOTENCY_KEY_REQUIRED',
                    'message' => 'هدر Idempotency-Key الزامی است.',
                ],
            ], 400);
        }

        if (! Str::isUuid($key)) {
            return response()->json([
                'error' => ['code' => 'IDEMPOTENCY_KEY_INVALID'],
            ], 400);
        }

        $orgId = $request->user()->organization_id;
        $hash  = hash('sha256', $request->getContent());

        $record = IdempotencyKey::where('key', $key)
            ->where('organization_id', $orgId)
            ->first();

        if ($record !== null) {
            if ($record->request_hash !== $hash) {
                return response()->json([
                    'error' => ['code' => 'IDEMPOTENCY_KEY_REUSED'],
                ], 422);
            }

            if ($record->status === 'processing') {
                return response()->json([
                    'error' => ['code' => 'IDEMPOTENCY_IN_PROGRESS'],
                ], 409);
            }

            return response()
                ->json($record->response_body, $record->response_code)
                ->header('X-Idempotent-Replay', 'true');
        }

        // ثبت اتمیک — از race condition جلوگیری می‌کند
        try {
            $record = IdempotencyKey::create([
                'key'             => $key,
                'organization_id' => $orgId,
                'endpoint'        => $request->path(),
                'request_hash'    => $hash,
                'status'          => 'processing',
                'locked_at'       => now(),
                'expires_at'      => now()->addDay(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'error' => ['code' => 'IDEMPOTENCY_IN_PROGRESS'],
            ], 409);
        }

        $response = $next($request);

        $record->update([
            'status'        => $response->isSuccessful() ? 'completed' : 'failed',
            'response_code' => $response->getStatusCode(),
            'response_body' => json_decode($response->getContent(), true),
        ]);

        return $response;
    }
}
```

<div dir="rtl">

---

## ۱.۷ پیکربندی

</div>

```php
// config/goldb2b.php

return [
    'market' => [
        'open_time'      => env('MARKET_OPEN_TIME', '09:00'),
        'close_time'     => env('MARKET_CLOSE_TIME', '17:30'),
        'pre_open_time'  => env('MARKET_PRE_OPEN_TIME', '08:45'),
        'timezone'       => 'Asia/Tehran',
    ],

    'ledger' => [
        'reconcile_hour'     => 2,
        'snapshot_hour'      => 3,
        'lock_timeout_sec'   => 10,
        'hash_chain_enabled' => env('LEDGER_HASH_CHAIN', true),
    ],

    'settlement' => [
        'default_deadline_hours' => 8,
        'overdue_check_minutes'  => 10,
        'penalty_daily_x100k'    => 50,
    ],

    'fees' => [
        'default_taker_x100k' => 150,
        'default_maker_x100k' => 100,
    ],

    'pricing' => [
        'max_staleness_sec'     => 300,
        'circuit_breaker_bps'   => 300,
        'max_order_deviation_bps' => 1000,
    ],

    'units' => [
        'mg_per_gram'      => 1000,
        'mesghal_mg_x10000'=> 46_083_000,
        'ounce_mg_x10000'  => 311_034_768,
    ],
];
```

<div dir="rtl">

**قاعده:** تنظیمات **قابل تغییر در زمان اجرا** در جدول `system_settings`،
تنظیمات **ثابت زیرساختی** در `config/`. هرگز عدد جادویی در کد.

---

## ۱.۸ ابزارهای کیفیت

</div>

```json
// composer.json — scripts
{
  "scripts": {
    "test":        "pest --parallel",
    "test:cov":    "pest --coverage --min=80",
    "lint":        "pint --test",
    "lint:fix":    "pint",
    "analyse":     "phpstan analyse --memory-limit=1G",
    "deps":        "deptrac analyse --fail-on-uncovered",
    "check":       ["@lint", "@analyse", "@deps", "@test"]
  }
}
```

<div dir="rtl">

</div>

```neon
# phpstan.neon
parameters:
    level: 8
    paths:
        - app
    excludePaths:
        - app/Modules/*/Database/Migrations/*
    checkMissingIterableValueType: true
    treatPhpDocTypesAsCertain: false
```

<div dir="rtl">

### قواعد سفارشی مهم

</div>

```php
// یک rule سفارشی PHPStan: منع float در مسیرهای مالی

final class NoFloatInFinancialCodeRule implements Rule
{
    private const FINANCIAL_NAMESPACES = [
        'App\Modules\Ledger',
        'App\Modules\Settlement',
        'App\Modules\Shared\ValueObjects',
        'App\Modules\Shared\Calculation',
    ];

    public function processNode(Node $node, Scope $scope): array
    {
        // بررسی هر cast یا type-hint به float/double
        // خطا اگر در namespace مالی باشد
    }
}
```

<div dir="rtl">

---

## ۱.۹ CI Pipeline

</div>

```yaml
# .github/workflows/ci.yml

name: CI
on: [push, pull_request]

jobs:
  quality:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_DATABASE: goldb2b_test
          MYSQL_ROOT_PASSWORD: secret
        options: >-
          --health-cmd="mysqladmin ping" --health-interval=10s
      redis:
        image: redis:7

    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: bcmath, redis, pdo_mysql
          coverage: xdebug

      - run: composer install --no-interaction --prefer-dist

      - name: Code style
        run: composer lint

      - name: Static analysis
        run: composer analyse

      - name: Module boundaries
        run: composer deps           # ◄── مرزهای ماژول

      - name: Migrations
        run: php artisan migrate --force

      - name: Tests
        run: composer test:cov

      - name: Ledger invariants          # ◄── حیاتی
        run: php artisan test --group=ledger-invariants

      - name: Security audit
        run: composer audit

      - name: Secret scan
        uses: gitleaks/gitleaks-action@v2
```

<div dir="rtl">

**قاعده:** build بدون عبور از **همه** مراحل بالا merge نمی‌شود.
به‌ویژه `deps` و `ledger-invariants` قابل نادیده گرفتن نیستند.

</div>
