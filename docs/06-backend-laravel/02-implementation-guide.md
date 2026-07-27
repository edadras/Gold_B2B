<div dir="rtl">

# ۲. راهنمای پیاده‌سازی

این سند الگوهای کدنویسی الزامی پروژه را تعریف می‌کند.

---

## ۲.۱ قواعد سخت

</div>

```
۱) هیچ float/double در مسیر مالی — فقط int و bcmath
۲) هیچ UPDATE روی ledger_entries و audit_logs
۳) هیچ فراخوانی HTTP خارجی داخل تراکنش دیتابیس
۴) هیچ صف/اعلان/broadcast داخل تراکنش
۵) هیچ کوئری داخل حلقه (N+1)
۶) هیچ رشته جادویی — همه‌چیز enum یا const
۷) هر عملیات مالی: تراکنش + قفل + Idempotency + Audit
۸) هر تراکنش: حداکثر ~۵۰۰ms
۹) هر Service با Interface تزریق می‌شود، نه با new
۱۰) هر متد عمومی: type-hint کامل ورودی و خروجی
```

<div dir="rtl">

---

## ۲.۲ الگوی Use Case

هر عملیات کسب‌وکار یک `Command` + یک `Handler` دارد.

</div>

```php
namespace App\Modules\Trading\Application\Commands;

final readonly class PlaceOrderCommand
{
    public function __construct(
        public int $organizationId,
        public int $userId,
        public ?int $representativeId,
        public string $instrumentCode,
        public Side $side,
        public OrderType $type,
        public TimeInForce $timeInForce,
        public FineWeight $quantity,
        public ?PricePerFineGram $price,
        public ?int $maxSlippageBps,
        public string $idempotencyKey,
    ) {
        if ($type === OrderType::LIMIT && $price === null) {
            throw new InvalidArgumentException('Limit order requires a price');
        }
    }

    public static function fromRequest(PlaceOrderRequest $r, User $user): self
    {
        return new self(
            organizationId:   $user->organization_id,
            userId:           $user->id,
            representativeId: $user->activeRepresentative()?->id,
            instrumentCode:   $r->string('instrument'),
            side:             Side::from($r->string('side')),
            type:             OrderType::from($r->string('type')),
            timeInForce:      TimeInForce::from($r->string('time_in_force', 'DAY')),
            quantity:         FineWeight::fromMilligrams($r->integer('quantity_mg')),
            price:            $r->has('price_rial')
                                ? PricePerFineGram::fromRial($r->integer('price_rial'))
                                : null,
            maxSlippageBps:   $r->integer('max_slippage_bps', null),
            idempotencyKey:   $r->header('Idempotency-Key'),
        );
    }
}
```

<div dir="rtl">

</div>

```php
namespace App\Modules\Trading\Application;

final readonly class PlaceOrderHandler
{
    public function __construct(
        private InstrumentRepositoryInterface $instruments,
        private RiskGuardInterface            $risk,
        private GoldLedgerInterface           $goldLedger,
        private RialLedgerInterface           $rialLedger,
        private TradeValueCalculator          $calculator,
        private MatchingEngine                $matcher,
        private OrderRepositoryInterface      $orders,
        private MarketSessionService          $session,
    ) {}

    public function handle(PlaceOrderCommand $cmd): OrderResult
    {
        // ── مرحله ۱: اعتبارسنجی (خارج از تراکنش) ────────────
        $instrument = $this->instruments->findByCodeOrFail($cmd->instrumentCode);

        $this->session->assertOpen($instrument);
        $this->assertQuantityValid($cmd, $instrument);
        $this->assertPriceValid($cmd, $instrument);
        $this->risk->assertTradeAllowed($cmd->toIntent());

        // ── مرحله ۲: تراکنش اتمیک ─────────────────────────
        $result = DB::transaction(function () use ($cmd, $instrument) {

            $requirement = $this->calculateRequirement($cmd, $instrument);

            $reservationId = $cmd->side === Side::BUY
                ? $this->rialLedger->reserve(
                      $cmd->organizationId,
                      $requirement->rial,
                      LedgerReference::order($cmd->idempotencyKey),
                  )
                : $this->goldLedger->reserve(
                      $cmd->organizationId,
                      $requirement->gold,
                      LedgerReference::order($cmd->idempotencyKey),
                  );

            $order = $this->orders->create($cmd, $instrument, $reservationId);

            $trades = $this->matcher->match($order);

            return new OrderResult($order->fresh(), $trades);

        }, attempts: 3);   // تلاش مجدد روی deadlock

        // ── مرحله ۳: رویدادها (پس از commit) ───────────────
        event(new OrderPlaced(
            orderId:        $result->order->id,
            organizationId: $cmd->organizationId,
            side:           $cmd->side->value,
            quantityMg:     $cmd->quantity->milligrams,
            priceRial:      $cmd->price?->rial,
            occurredAt:     $result->order->placed_at->toIso8601String(),
        ));

        foreach ($result->trades as $trade) {
            event(new TradeExecuted(
                tradeId:                $trade->id,
                buyerOrganizationId:    $trade->buyer_organization_id,
                sellerOrganizationId:   $trade->seller_organization_id,
                fineWeightMg:           $trade->quantity_fine_mg,
                pricePerGramRial:       $trade->price_per_gram_rial,
                grossAmountRial:        $trade->gross_amount_rial,
                occurredAt:             $trade->executed_at->toIso8601String(),
            ));
        }

        return $result;
    }
}
```

<div dir="rtl">

**ساختار همیشگی:** اعتبارسنجی → تراکنش → رویداد.

---

## ۲.۳ الگوی قفل‌گذاری

</div>

```php
namespace App\Modules\Ledger\Infrastructure\Locking;

final class AccountLocker
{
    /**
     * قفل چند حساب با ترتیب قطعی — جلوگیری از deadlock.
     * همیشه به ترتیب صعودی id.
     */
    public function lockAccounts(int ...$accountIds): Collection
    {
        sort($accountIds);   // ◄── حیاتی

        return LedgerAccountModel::query()
            ->whereIn('id', $accountIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * قفل همه حساب‌های چند سازمان با ترتیب قطعی.
     */
    public function lockOrganizations(AssetType $asset, int ...$orgIds): Collection
    {
        sort($orgIds);

        return LedgerAccountModel::query()
            ->whereIn('organization_id', $orgIds)
            ->where('asset_type', $asset->value)
            ->orderBy('organization_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
```

<div dir="rtl">

### قفل توزیع‌شده برای عملیات چندمرحله‌ای

</div>

```php
// وقتی عملیات از چند تراکنش تشکیل شده و باید سریالی باشد

$lock = Cache::lock("netting:batch:{$batchId}", 300);

if (! $lock->get()) {
    throw new OperationInProgressException('تهاتر در حال اجراست.');
}

try {
    $this->executeNetting($batchId);
} finally {
    $lock->release();
}
```

<div dir="rtl">

---

## ۲.۴ الگوی نوشتن در دفتر

</div>

```php
namespace App\Modules\Ledger\Application;

final class GoldLedgerService implements GoldLedgerInterface
{
    /**
     * ⚠️ این متد باید داخل DB::transaction فراخوانی شود.
     */
    private function writeEntry(
        LedgerAccountModel $account,
        int $amount,
        EntryType $type,
        TransactionGroup $group,
        LedgerReference $ref,
        ?string $description = null,
    ): LedgerEntryModel {
        if (! DB::transactionLevel()) {
            throw new LogicException('writeEntry must run inside a transaction');
        }

        if ($amount === 0) {
            throw new InvalidArgumentException('Entry amount cannot be zero');
        }

        // ۱) قفل و خواندن مانده فعلی
        $balance = LedgerBalanceModel::where('account_id', $account->id)
            ->lockForUpdate()
            ->firstOrFail();

        $newBalance = $balance->balance + $amount;

        // ۲) بررسی منفی نشدن
        if ($newBalance < 0 && ! $account->allows_negative) {
            throw new InsufficientBalanceException(
                accountId: $account->id,
                required: abs($amount),
                available: $balance->balance,
            );
        }

        // ۳) زنجیره hash
        $prevHash = $this->lastHashFor($account->id);

        $entry = new LedgerEntryModel([
            'account_id'        => $account->id,
            'organization_id'   => $account->organization_id,
            'asset_type'        => $account->asset_type,
            'amount'            => $amount,
            'entry_type'        => $type->value,
            'direction'         => $amount > 0 ? 'CREDIT' : 'DEBIT',
            'reference_type'    => $ref->type,
            'reference_id'      => $ref->id,
            'transaction_group' => $group->value,
            'balance_after'     => $newBalance,
            'description'       => $description,
            'created_by_user_id'=> auth()->id(),
            'prev_hash'         => $prevHash,
        ]);

        $entry->row_hash = $this->hashChain->compute($entry, $prevHash);
        $entry->save();

        // ۴) به‌روزرسانی کش مانده
        $balance->update([
            'balance'       => $newBalance,
            'last_entry_id' => $entry->id,
            'entry_count'   => $balance->entry_count + 1,
            'version'       => $balance->version + 1,
        ]);

        return $entry;
    }

    /**
     * بررسی بقای جرم برای یک گروه تراکنش.
     * ⚠️ در پایان هر عملیات چندمرحله‌ای فراخوانی شود.
     */
    private function assertGroupBalances(TransactionGroup $group): void
    {
        $sums = LedgerEntryModel::where('transaction_group', $group->value)
            ->groupBy('asset_type')
            ->selectRaw('asset_type, SUM(amount) AS total')
            ->pluck('total', 'asset_type');

        foreach ($sums as $asset => $total) {
            if ((int) $total !== 0) {
                throw new UnbalancedTransactionException($group, $asset, (int) $total);
            }
        }
    }
}
```

<div dir="rtl">

---

## ۲.۵ الگوی موتور تطبیق

</div>

```php
namespace App\Modules\Trading\Application;

final class MatchingEngine
{
    /**
     * ⚠️ باید داخل تراکنش فراخوانی شود.
     * @return list<TradeModel>
     */
    public function match(OrderModel $incoming): array
    {
        $trades = [];
        $remaining = $incoming->quantity_mg - $incoming->filled_mg;

        $candidates = $this->findMatchableOrders($incoming);

        foreach ($candidates as $maker) {
            if ($remaining <= 0) {
                break;
            }

            // ⚠️ بررسی مجدد Self-Trade (دفاع در عمق)
            if ($maker->organization_id === $incoming->organization_id) {
                Log::warning('Self-trade candidate slipped through query', [
                    'incoming' => $incoming->id,
                    'maker'    => $maker->id,
                ]);
                continue;
            }

            $makerRemaining = $maker->quantity_mg - $maker->filled_mg;
            $qty = min($remaining, $makerRemaining);

            // قیمت اجرا = قیمت maker
            $executionPrice = PricePerFineGram::fromRial($maker->price_rial);

            if ($incoming->order_type === 'MARKET'
                && $this->exceedsSlippage($executionPrice, $incoming)) {
                break;
            }

            $trades[] = $this->createTrade(
                maker: $maker,
                taker: $incoming,
                quantity: FineWeight::fromMilligrams($qty),
                price: $executionPrice,
            );

            $maker->increment('filled_mg', $qty);
            $this->updateOrderStatus($maker);

            $remaining -= $qty;
        }

        $incoming->filled_mg = $incoming->quantity_mg - $remaining;
        $this->updateOrderStatus($incoming);

        // FOK: یا همه یا هیچ
        if ($incoming->time_in_force === 'FOK' && $remaining > 0) {
            throw new FillOrKillNotFilledException();   // rollback تراکنش
        }

        // IOC: باقیمانده لغو
        if ($incoming->time_in_force === 'IOC' && $remaining > 0) {
            $this->cancelRemaining($incoming);
        }

        return $trades;
    }

    private function findMatchableOrders(OrderModel $incoming): Collection
    {
        $query = OrderModel::query()
            ->where('instrument_id', $incoming->instrument_id)
            ->where('side', $incoming->side === 'BUY' ? 'SELL' : 'BUY')
            ->whereIn('status', ['OPEN', 'PARTIALLY_FILLED'])
            ->where('organization_id', '!=', $incoming->organization_id)
            ->whereRaw('quantity_mg > filled_mg');

        // بلاک طرف‌حساب
        $query->whereNotExists(function ($q) use ($incoming) {
            $q->select(DB::raw(1))
              ->from('counterparty_relations AS cr')
              ->whereColumn('cr.organization_id', 'orders.organization_id')
              ->where('cr.counterparty_org_id', $incoming->organization_id)
              ->where('cr.is_blocked', true);
        });

        if ($incoming->order_type === 'LIMIT') {
            $incoming->side === 'BUY'
                ? $query->where('price_rial', '<=', $incoming->price_rial)
                : $query->where('price_rial', '>=', $incoming->price_rial);
        }

        // اولویت price-time
        $incoming->side === 'BUY'
            ? $query->orderBy('price_rial', 'asc')
            : $query->orderBy('price_rial', 'desc');

        return $query
            ->orderBy('placed_at', 'asc')
            ->lockForUpdate()
            ->limit(100)
            ->get();
    }
}
```

<div dir="rtl">

> ⚠️ نکته: `SKIP LOCKED` در Eloquent با `->lockForUpdate()` در دسترس نیست.
> برای استفاده: `->lock('FOR UPDATE SKIP LOCKED')`.
> اما در Matching Engine، `SKIP LOCKED` می‌تواند باعث از دست رفتن
> بهترین قیمت شود. تصمیم: **بدون SKIP LOCKED** در تطبیق، با
> `innodb_lock_wait_timeout = 10` و تلاش مجدد.

---

## ۲.۶ مدیریت استثنا

</div>

```php
// app/Exceptions/Handler.php  (یا bootstrap/app.php در Laravel 11)

->withExceptions(function (Exceptions $exceptions) {

    $exceptions->render(function (DomainException $e, Request $request) {
        if (! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'error' => [
                'code'    => $e->errorCode(),
                'message' => $e->userMessage(),
                'details' => $e->details(),
            ],
            'meta' => [
                'request_id'  => $request->attributes->get('request_id'),
                'server_time' => now()->toIso8601String(),
            ],
        ], $e->httpStatus());
    });

    // هرگز جزئیات داخلی را در تولید افشا نکن
    $exceptions->render(function (Throwable $e, Request $request) {
        if (! app()->isProduction() || ! $request->expectsJson()) {
            return null;
        }

        report($e);

        return response()->json([
            'error' => [
                'code'    => 'INTERNAL_ERROR',
                'message' => 'خطای داخلی رخ داد. لطفاً بعداً تلاش کنید.',
            ],
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ], 500);
    });
})
```

<div dir="rtl">

### پایه استثناهای دامنه

</div>

```php
namespace App\Modules\Shared\Exceptions;

abstract class DomainException extends \RuntimeException
{
    abstract public function errorCode(): string;
    abstract public function userMessage(): string;

    public function httpStatus(): int { return 422; }

    /** @return array<string, mixed> */
    public function details(): array { return []; }
}
```

<div dir="rtl">

</div>

```php
final class InsufficientBalanceException extends DomainException
{
    public function __construct(
        private readonly int $required,
        private readonly int $available,
        private readonly string $asset = 'GOLD',
    ) {
        parent::__construct("Insufficient {$asset} balance");
    }

    public function errorCode(): string
    {
        return $this->asset === 'GOLD' ? 'INSUFFICIENT_GOLD' : 'INSUFFICIENT_RIAL';
    }

    public function userMessage(): string
    {
        return $this->asset === 'GOLD'
            ? 'موجودی طلای شما برای این عملیات کافی نیست.'
            : 'موجودی ریالی شما برای این عملیات کافی نیست.';
    }

    public function details(): array
    {
        return [
            'required'  => $this->required,
            'available' => $this->available,
            'shortfall' => $this->required - $this->available,
        ];
    }
}
```

<div dir="rtl">

---

## ۲.۷ الگوی تست

### تست ثابت‌های دفتر (الزامی)

</div>

```php
// app/Modules/Ledger/Tests/Property/LedgerInvariantTest.php

/**
 * @group ledger-invariants
 */
final class LedgerInvariantTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function total_gold_is_conserved_under_random_operations(): void
    {
        $orgs = Organization::factory()->count(8)->active()->create();

        foreach ($orgs as $org) {
            $this->depositGold($org, FineWeight::fromGrams(1000));
        }

        $before = $this->systemGoldTotal();

        for ($i = 0; $i < 500; $i++) {
            try {
                $this->randomOperation($orgs);
            } catch (DomainException) {
                // رد شدن عملیات طبیعی است — نباید مانده را تغییر دهد
            }
        }

        $this->assertSame($before, $this->systemGoldTotal(),
            'Gold was created or destroyed by random operations');
    }

    /** @test */
    public function stored_balance_always_matches_computed(): void
    {
        $org = Organization::factory()->active()->create();
        $this->depositGold($org, FineWeight::fromGrams(500));

        for ($i = 0; $i < 100; $i++) {
            $this->randomOperation(collect([$org]));
        }

        foreach ($org->ledgerAccounts as $account) {
            $stored   = LedgerBalance::where('account_id', $account->id)->value('balance');
            $computed = LedgerEntry::where('account_id', $account->id)->sum('amount');

            $this->assertSame((int) $computed, (int) $stored,
                "Balance mismatch on account {$account->id}");
        }
    }

    /** @test */
    public function every_transaction_group_sums_to_zero(): void
    {
        $this->performVariousOperations();

        $unbalanced = DB::table('ledger_entries')
            ->select('transaction_group', 'asset_type')
            ->groupBy('transaction_group', 'asset_type')
            ->havingRaw('SUM(amount) <> 0')
            ->get();

        $this->assertCount(0, $unbalanced,
            'Found unbalanced transaction groups: ' . $unbalanced->toJson());
    }

    /** @test */
    public function concurrent_reservations_cannot_oversell(): void
    {
        $org = Organization::factory()->active()->create();
        $this->depositGold($org, FineWeight::fromGrams(100));

        $results = $this->runConcurrently(5, function () use ($org) {
            try {
                app(GoldLedgerInterface::class)->reserve(
                    $org->id,
                    FineWeight::fromGrams(80),
                    LedgerReference::test(),
                );
                return 'ok';
            } catch (InsufficientBalanceException) {
                return 'rejected';
            }
        });

        // فقط یکی باید موفق شود
        $this->assertSame(1, collect($results)->filter(fn ($r) => $r === 'ok')->count());
    }
}
```

<div dir="rtl">

### تست اجرای همزمان

</div>

```php
protected function runConcurrently(int $count, Closure $job): array
{
    $pids = [];
    $sockets = [];

    for ($i = 0; $i < $count; $i++) {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $pid = pcntl_fork();

        if ($pid === 0) {
            fclose($parent);
            DB::reconnect();          // اتصال جدید در فرزند
            fwrite($child, serialize($job()));
            fclose($child);
            exit(0);
        }

        fclose($child);
        $sockets[] = $parent;
        $pids[] = $pid;
    }

    $results = array_map(fn ($s) => unserialize(stream_get_contents($s)), $sockets);
    foreach ($pids as $pid) { pcntl_waitpid($pid, $status); }

    return $results;
}
```

<div dir="rtl">

---

## ۲.۸ سطوح تست

| سطح | چه چیزی | ابزار | پوشش هدف |
|---|---|---|---|
| Unit | Value Object، Calculator، Domain Service | Pest | ۱۰۰٪ |
| Feature | HTTP endpoint با دیتابیس | Pest + RefreshDatabase | ۸۰٪ |
| Property | ثابت‌های دفتر و تسویه | Pest + تولید تصادفی | همه ثابت‌ها |
| Integration | جریان کامل (سفارش تا تسویه) | Pest | مسیرهای اصلی |
| Concurrency | Race condition | pcntl_fork | عملیات مالی |

**قاعده:** هیچ کد مالی بدون تست property-based merge نمی‌شود.

---

## ۲.۹ لاگ‌گذاری

</div>

```php
// همیشه ساختاریافته، هرگز رشته خام

Log::channel('financial')->info('order.placed', [
    'order_id'        => $order->id,
    'organization_id' => $order->organization_id,
    'side'            => $order->side,
    'quantity_mg'     => $order->quantity_mg,
    'price_rial'      => $order->price_rial,
    'request_id'      => request()->attributes->get('request_id'),
]);

// ❌ هرگز داده حساس در لاگ
Log::info("User {$user->national_id} logged in");   // بد

// ✅
Log::info('auth.login', ['user_id' => $user->id]);
```

<div dir="rtl">

### کانال‌های لاگ

</div>

```php
// config/logging.php
'channels' => [
    'financial' => [        // معاملات، دفتر، تسویه — نگهداری ۱۰ سال
        'driver' => 'daily',
        'path'   => storage_path('logs/financial.log'),
        'days'   => 3650,
        'level'  => 'info',
    ],
    'security' => [         // ورود، تغییر دسترسی، تلاش ناموفق
        'driver' => 'daily',
        'path'   => storage_path('logs/security.log'),
        'days'   => 1095,
    ],
    'compliance' => [       // AML — دسترسی محدود
        'driver' => 'daily',
        'path'   => storage_path('logs/compliance.log'),
        'days'   => 3650,
        'permission' => 0600,
    ],
],
```

<div dir="rtl">

---

## ۲.۱۰ چک‌لیست بازبینی کد (Code Review)

</div>

```
عمومی
[ ] هیچ float در مسیر مالی
[ ] هیچ عدد جادویی — همه از config یا const
[ ] type-hint کامل روی همه متدها
[ ] بدون N+1 (بررسی با Laravel Debugbar یا تست)

مالی
[ ] عملیات مالی داخل DB::transaction
[ ] قفل‌گذاری با ترتیب ثابت
[ ] Idempotency-Key اعمال شده
[ ] assertGroupBalances فراخوانی شده
[ ] رویدادها پس از commit منتشر می‌شوند
[ ] هیچ UPDATE/DELETE روی ledger_entries

امنیت
[ ] بررسی tenancy صریح (نه فقط global scope)
[ ] بررسی permission
[ ] ورودی اعتبارسنجی شده
[ ] بدون داده حساس در لاگ یا پاسخ خطا
[ ] Rate limit روی endpoint جدید

تست
[ ] تست happy path
[ ] تست مسیرهای خطا
[ ] تست ثابت‌ها (اگر مالی است)
[ ] تست همزمانی (اگر روی موجودی اثر دارد)

مستندات
[ ] OpenAPI به‌روز شده
[ ] اگر مدل داده تغییر کرده، docs/04-data/ به‌روز شده
[ ] اگر تصمیم معماری است، ADR اضافه شده
```

</div>
