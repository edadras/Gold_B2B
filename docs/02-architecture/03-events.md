<div dir="rtl">

# ۳. رویدادها، صف‌ها و پردازش ناهمگام

## ۳.۱ چرا رویداد؟

سه دلیل مشخص:

1. **کوتاه نگه‌داشتن تراکنش مالی** — قفل دیتابیس نباید منتظر ارسال SMS بماند
2. **جداسازی ماژول‌ها** — `Trading` نباید بداند `Notification` وجود دارد
3. **قابلیت بازپخش (Replay)** — می‌توان گزارش‌ها را از رویدادها بازسازی کرد

---

## ۳.۲ توپولوژی صف‌ها

</div>

```
┌────────────────────────────────────────────────────────────┐
│  Redis Queues (اولویت از بالا به پایین)                     │
├────────────────────────────────────────────────────────────┤
│                                                            │
│  settlement      ◄── بحرانی، تأخیر غیرقابل قبول             │
│                     SettleTrade, ReleaseEscrow             │
│                     workers: 4    timeout: 60s             │
│                                                            │
│  ledger-sync     ◄── همگام‌سازی snapshot مانده              │
│                     workers: 2    timeout: 30s             │
│                                                            │
│  broadcast       ◄── به‌روزرسانی WebSocket                  │
│                     workers: 4    timeout: 10s             │
│                                                            │
│  notification    ◄── Push، SMS، Email                       │
│                     workers: 6    timeout: 30s             │
│                                                            │
│  accounting      ◄── ثبت‌های حسابداری                       │
│                     workers: 2    timeout: 60s             │
│                                                            │
│  risk            ◄── ارزیابی AML و امتیاز                   │
│                     workers: 2    timeout: 120s            │
│                                                            │
│  reporting       ◄── تولید گزارش سنگین                      │
│                     workers: 2    timeout: 600s            │
│                                                            │
│  default         ◄── سایر                                   │
│                     workers: 4                             │
└────────────────────────────────────────────────────────────┘
```

<div dir="rtl">

اجرای worker:

</div>

```bash
php artisan queue:work redis \
  --queue=settlement,ledger-sync,broadcast,notification,accounting,risk,default \
  --tries=3 --backoff=5,15,60 --max-time=3600
```

<div dir="rtl">

---

## ۳.۳ قواعد نوشتن Listener

### قاعده ۱ — Idempotent باشد

هر listener ممکن است **بیش از یک بار** اجرا شود (retry, redelivery).

</div>

```php
class RecordAccountingEntry implements ShouldQueue
{
    public function handle(TradeExecuted $event): void
    {
        // بررسی اجرای قبلی
        if (JournalEntry::where('source_type', 'trade')
                        ->where('source_id', $event->tradeId)
                        ->exists()) {
            return;   // قبلاً ثبت شده
        }

        // ...
    }
}
```

<div dir="rtl">

یا با کلید یکتا در سطح دیتابیس:

</div>

```sql
UNIQUE KEY uq_journal_source (source_type, source_id, entry_kind)
```

<div dir="rtl">

### قاعده ۲ — از داده رویداد استفاده کن، نه از وضعیت فعلی

</div>

```php
// ❌ خطرناک — trade ممکن است تغییر کرده باشد
$trade = Trade::find($event->tradeId);
$amount = $trade->gross_amount;

// ✅ درست — عدد لحظه وقوع رویداد
$amount = $event->grossAmountRial;
```

<div dir="rtl">

### قاعده ۳ — شکست یک listener نباید بقیه را متوقف کند

هر listener جداگانه صف می‌شود؛ Laravel این را به‌صورت پیش‌فرض انجام می‌دهد
وقتی هر listener `ShouldQueue` باشد.

### قاعده ۴ — Dead Letter Queue

</div>

```php
public int $tries = 3;
public array $backoff = [5, 30, 120];

public function failed(TradeExecuted $event, Throwable $e): void
{
    FailedEventLog::create([
        'event'   => TradeExecuted::class,
        'payload' => json_encode($event),
        'error'   => $e->getMessage(),
        'trace'   => $e->getTraceAsString(),
    ]);

    // اعلان به تیم عملیات برای رویدادهای مالی
    Alert::critical("Listener failed: {$event->tradeId}");
}
```

<div dir="rtl">

جدول `failed_jobs` باید **روزانه بررسی شود**. رویداد مالی شکست‌خورده
یعنی مغایرت.

---

## ۳.۴ کاتالوگ کامل رویدادها

### Identity

| رویداد | زمان انتشار | payload |
|---|---|---|
| `UserRegistered` | پس از تأیید OTP | `userId, phone` |
| `OrganizationCreated` | پس از ثبت اطلاعات اولیه | `organizationId, type, name` |
| `OrganizationActivated` | پس از تأیید KYC | `organizationId, activatedAt` |
| `OrganizationSuspended` | تعلیق | `organizationId, reason, byUserId` |
| `UserRoleChanged` | تغییر نقش | `userId, oldRoles, newRoles, byUserId` |

### Kyc

| رویداد | payload |
|---|---|
| `KycSubmitted` | `organizationId, submittedAt` |
| `KycApproved` | `organizationId, officerId, notes` |
| `KycRejected` | `organizationId, officerId, reason` |
| `KycInfoRequired` | `organizationId, missingItems[]` |
| `LicenseExpiring` | `organizationId, expiresAt, daysLeft` |

### Custody

| رویداد | payload |
|---|---|
| `GoldLotCreated` | `lotId, ownerId, grossWeightMg, purity, source` |
| `AssayRecorded` | `lotId, assayId, purity, labId, certificateNo` |
| `LotSplit` | `parentLotId, childLotIds[], weights[]` |
| `LotsMerged` | `parentLotIds[], newLotId` |
| `LotMelted` | `inputLotIds[], outputLotIds[], lossMg` |
| `OwnershipTransferred` | `lotId, fromOrgId, toOrgId, tradeId` |
| `LotEnteredVault` | `lotId, vaultId, locationCode, byUserId` |
| `LotLeftVault` | `lotId, vaultId, receiverOrgId, waybillNo` |

### Ledger

| رویداد | payload |
|---|---|
| `LedgerAccountCreated` | `accountId, organizationId, type` |
| `BalanceReserved` | `accountId, amount, referenceType, referenceId` |
| `ReservationReleased` | `reservationEntryId, reason` |
| `TransferCompleted` | `fromAccountId, toAccountId, amount, referenceId` |
| `EntryReversed` | `originalEntryId, reversalEntryId, reason, byUserId` |
| `BalanceDiscrepancyDetected` | `accountId, storedBalance, computedBalance` ⚠️ بحرانی |

### Trading

| رویداد | payload |
|---|---|
| `OrderPlaced` | `orderId, orgId, side, type, fineWeightMg, priceRial` |
| `OrderPartiallyFilled` | `orderId, filledMg, remainingMg` |
| `OrderFilled` | `orderId` |
| `OrderCancelled` | `orderId, reason` |
| `OrderRejected` | `orderId, reason` |
| `TradeExecuted` | `tradeId, buyerOrgId, sellerOrgId, fineWeightMg, priceRial, grossRial` |
| `RfqCreated` | `rfqId, orgId, side, weightMg, recipientOrgIds[]` |
| `RfqQuoted` | `rfqId, quoteId, quoterOrgId, priceRial` |
| `RfqAccepted` | `rfqId, quoteId, tradeId` |
| `RfqExpired` | `rfqId` |
| `MarketSessionOpened` / `Closed` | `instrumentId, at` |
| `CircuitBreakerTriggered` | `instrumentId, reason, priceChangePercent` |

### Settlement

| رویداد | payload |
|---|---|
| `SettlementOpened` | `settlementId, tradeId, deadline` |
| `PaymentDeclaredByPayer` | `settlementId, reference, amount` |
| `PaymentConfirmedByPayee` | `settlementId, confirmedAt` |
| `GoldTransferInitiated` | `settlementId, lotIds[]` |
| `SettlementCompleted` | `settlementId, tradeId, completedAt` |
| `SettlementOverdue` | `settlementId, overdueBy` |
| `SettlementCancelled` | `settlementId, reason` |
| `SettlementReversed` | `settlementId, reason, byUserId` |
| `NettingProposed` | `nettingId, participantOrgIds[], netPositions[]` |
| `NettingAccepted` | `nettingId, orgId` |
| `NettingExecuted` | `nettingId, transferCount` |

### Risk

| رویداد | payload |
|---|---|
| `LimitExceeded` | `orgId, limitType, requested, available` |
| `AmlFlagRaised` | `orgId, ruleCode, severity, context` |
| `RiskProfileChanged` | `orgId, oldLevel, newLevel, reason` |
| `CreditScoreUpdated` | `orgId, oldScore, newScore` |

### Dispute

| رویداد | payload |
|---|---|
| `DisputeOpened` | `disputeId, tradeId, byOrgId, type, claimAmount` |
| `DisputeEvidenceAdded` | `disputeId, evidenceId, byOrgId` |
| `DisputeResolved` | `disputeId, decision, resolvedByUserId` |
| `DisputeEscalated` | `disputeId, toLevel` |

---

## ۳.۵ نگاشت رویداد به listener

</div>

```
TradeExecuted
    │
    ├──► [settlement]    OpenSettlement          (اولویت بالا)
    ├──► [broadcast]     BroadcastTradeToMarket
    ├──► [broadcast]     BroadcastToParties
    ├──► [notification]  NotifyBuyer
    ├──► [notification]  NotifySeller
    ├──► [accounting]    RecordTradeJournal
    ├──► [risk]          UpdateDailyVolumeCounter
    ├──► [risk]          EvaluateAmlRules
    ├──► [default]       UpdateReputationStats
    └──► [default]       WriteAuditLog


SettlementCompleted
    │
    ├──► [accounting]    RecordSettlementJournal
    ├──► [notification]  NotifyBothParties
    ├──► [default]       UpdateOnTimeSettlementRate
    ├──► [default]       ReleaseRemainingReservations
    └──► [reporting]     InvalidateDailyReportCache


SettlementOverdue
    │
    ├──► [notification]  NotifyDebtor          (فوری)
    ├──► [notification]  NotifyCreditor
    ├──► [risk]          ApplyOverduePenalty
    ├──► [risk]          DowngradeCreditScore
    └──► [notification]  AlertComplianceOfficer


BalanceDiscrepancyDetected      ⚠️ بحرانی
    │
    ├──► [settlement]    FreezeOrganizationOperations
    ├──► [notification]  AlertOpsTeamImmediately
    └──► [default]       CreateIncidentRecord
```

<div dir="rtl">

---

## ۳.۶ کارهای زمان‌بندی‌شده (Scheduled Jobs)

</div>

```php
// app/Console/Kernel.php  (یا routes/console.php در Laravel 11)

$schedule->command('market:open')->dailyAt('09:00')->weekdays();
$schedule->command('market:close')->dailyAt('17:30')->weekdays();

$schedule->command('pricing:fetch-reference')->everyMinute();
$schedule->command('pricing:build-ohlc')->everyFiveMinutes();

$schedule->command('settlement:check-overdue')->everyTenMinutes();
$schedule->command('settlement:propose-netting')->dailyAt('17:35')->weekdays();

$schedule->command('ledger:reconcile')->dailyAt('02:00');        // ⚠️ حیاتی
$schedule->command('ledger:snapshot')->dailyAt('02:30');

$schedule->command('kyc:check-expiring-licenses')->dailyAt('08:00');
$schedule->command('risk:recalculate-scores')->dailyAt('03:00');
$schedule->command('risk:reset-daily-counters')->dailyAt('00:00');

$schedule->command('report:generate-daily')->dailyAt('18:00');
$schedule->command('audit:archive-old')->weekly();

$schedule->command('rfq:expire-stale')->everyMinute();
$schedule->command('orders:expire-gtd')->everyMinute();
```

<div dir="rtl">

### `ledger:reconcile` — مهم‌ترین job سیستم

</div>

```
برای هر حساب دفتر:
    stored  = ledger_balances.available + ledger_balances.reserved
    computed = SUM(ledger_entries.amount WHERE account_id = ?)

    اگر stored != computed:
        ► ثبت BalanceDiscrepancyDetected
        ► انجماد فوری عملیات آن سازمان
        ► اعلان بحرانی به تیم عملیات
        ► ثبت Incident

همچنین بررسی بقای جرم کل سیستم:
    SUM(all gold ledger entries) == 0   (سیستم بسته)
    یا
    SUM(entries) == SUM(gold_lots.fine_weight WHERE status = ACTIVE)
```

<div dir="rtl">

---

## ۳.۷ ترتیب رویدادها

Redis queue تضمین ترتیب سراسری نمی‌دهد. برای مواردی که ترتیب مهم است:

**راهکار ۱ — صف اختصاصی تک‌worker**

</div>

```php
// همه رویدادهای یک سازمان روی یک صف
public function viaQueue(): string
{
    return 'settlement-' . ($this->organizationId % 4);
}
```

<div dir="rtl">

**راهکار ۲ — بررسی نسخه (Optimistic)**

</div>

```php
if ($event->version <= $aggregate->last_processed_version) {
    return;   // رویداد قدیمی، نادیده بگیر
}
```

<div dir="rtl">

**راهکار ۳ — طراحی listener به‌گونه‌ای که به ترتیب حساس نباشد** (ترجیحی)

---

## ۳.۸ مانیتورینگ رویدادها

| متریک | آستانه هشدار |
|---|---|
| عمق صف `settlement` | > ۱۰۰ |
| عمق صف `notification` | > ۱۰۰۰ |
| تعداد `failed_jobs` | > ۰ برای صف‌های مالی |
| تأخیر پردازش p95 صف settlement | > ۳۰ ثانیه |
| نرخ `BalanceDiscrepancyDetected` | > ۰ ⚠️ |
| زمان اجرای `ledger:reconcile` | > ۱۰ دقیقه |

</div>
