<div dir="rtl">

# ۲. ماشین‌های حالت — مرجع کامل

همه ماشین‌های حالت سیستم در یک جا. هر گذار باید در کد به‌صورت صریح
تعریف و اعتبارسنجی شود؛ تغییر وضعیت خودسرانه ممنوع است.

---

## ۲.۱ الگوی پیاده‌سازی

</div>

```php
namespace App\Modules\Settlement\Domain;

enum SettlementStatus: string
{
    case CREATED            = 'CREATED';
    case ASSETS_LOCKED      = 'ASSETS_LOCKED';
    case PAYMENT_PENDING    = 'PAYMENT_PENDING';
    case PAYMENT_DECLARED   = 'PAYMENT_DECLARED';
    case PAYMENT_CONFIRMED  = 'PAYMENT_CONFIRMED';
    case GOLD_TRANSFERRING  = 'GOLD_TRANSFERRING';
    case SETTLED            = 'SETTLED';
    case COMPLETED          = 'COMPLETED';
    case OVERDUE            = 'OVERDUE';
    case DEFAULTED          = 'DEFAULTED';
    case CANCELLED          = 'CANCELLED';
    case DISPUTED           = 'DISPUTED';
    case REVERSED           = 'REVERSED';
    case NETTING_QUEUE      = 'NETTING_QUEUE';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::CREATED => [
                self::ASSETS_LOCKED, self::CANCELLED,
            ],
            self::ASSETS_LOCKED => [
                self::PAYMENT_PENDING, self::NETTING_QUEUE,
                self::CANCELLED, self::DISPUTED,
            ],
            self::PAYMENT_PENDING => [
                self::PAYMENT_DECLARED, self::OVERDUE,
                self::CANCELLED, self::DISPUTED,
            ],
            self::PAYMENT_DECLARED => [
                self::PAYMENT_CONFIRMED, self::OVERDUE,
                self::DISPUTED,
            ],
            self::PAYMENT_CONFIRMED => [
                self::GOLD_TRANSFERRING, self::DISPUTED,
            ],
            self::GOLD_TRANSFERRING => [
                self::SETTLED, self::DISPUTED,
            ],
            self::SETTLED => [
                self::COMPLETED, self::DISPUTED, self::REVERSED,
            ],
            self::COMPLETED => [
                self::DISPUTED, self::REVERSED,
            ],
            self::OVERDUE => [
                self::PAYMENT_DECLARED, self::DEFAULTED,
                self::CANCELLED, self::DISPUTED,
            ],
            self::DEFAULTED => [
                self::SETTLED, self::CANCELLED, self::DISPUTED,
            ],
            self::NETTING_QUEUE => [
                self::SETTLED, self::PAYMENT_PENDING, self::CANCELLED,
            ],
            self::DISPUTED => [
                self::SETTLED, self::REVERSED, self::CANCELLED,
            ],
            self::CANCELLED, self::REVERSED => [],   // نهایی
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [
            self::COMPLETED, self::CANCELLED, self::REVERSED,
        ], true);
    }
}
```

<div dir="rtl">

</div>

```php
// اعمال در Service — هرگز مستقیم update نکنید

final class SettlementStateMachine
{
    public function transition(
        Settlement $settlement,
        SettlementStatus $target,
        TransitionContext $ctx,
    ): void {
        $current = SettlementStatus::from($settlement->status);

        if (! $current->canTransitionTo($target)) {
            throw new InvalidStateTransitionException(
                from: $current,
                to: $target,
                entity: 'Settlement',
                id: $settlement->id,
            );
        }

        DB::transaction(function () use ($settlement, $current, $target, $ctx) {
            $settlement->update(['status' => $target->value]);

            SettlementEvent::create([
                'settlement_id' => $settlement->id,
                'from_status'   => $current->value,
                'to_status'     => $target->value,
                'actor_type'    => $ctx->actorType,
                'actor_user_id' => $ctx->actorUserId,
                'reason'        => $ctx->reason,
                'metadata'      => $ctx->metadata,
            ]);

            // اثرات جانبی هر گذار
            $this->applySideEffects($settlement, $current, $target);
        });
    }
}
```

<div dir="rtl">

---

## ۲.۲ Organization

</div>

```
PENDING
  └─► UNDER_REVIEW          (ارسال مدارک)

UNDER_REVIEW
  ├─► VERIFIED              (تأیید انطباق)
  ├─► INFO_REQUIRED         (نقص مدرک)
  └─► REJECTED              (رد — نهایی)

INFO_REQUIRED
  └─► UNDER_REVIEW          (تکمیل مدرک)

VERIFIED
  └─► ACTIVE                (خودکار — ایجاد دفتر و سقف)

ACTIVE
  ├─► RESTRICTED            (نقض سقف/AML/انقضای مجوز)
  ├─► SUSPENDED             (تخلف جدی)
  └─► CLOSING               (درخواست خاتمه)

RESTRICTED
  ├─► ACTIVE                (رفع محدودیت)
  └─► SUSPENDED             (تشدید)

SUSPENDED
  ├─► ACTIVE                (رفع تعلیق — تأیید دوگانه)
  └─► CLOSING

CLOSING
  └─► CLOSED                (پس از تسویه کامل — نهایی)

REJECTED / CLOSED  ► نهایی
```

<div dir="rtl">

### شرایط ورود به `CLOSED`

</div>

```
✓ مانده طلا = 0 در همه bucketها
✓ مانده ریال = 0 در همه bucketها
✓ هیچ سفارش باز
✓ هیچ تسویه باز
✓ هیچ اختلاف باز
✓ هیچ lot تحت مالکیت
✓ هیچ تعهد باز با طرف‌حساب
```

<div dir="rtl">

---

## ۲.۳ Order

</div>

```
PENDING
  ├─► OPEN                  (رزرو موفق)
  └─► REJECTED              (رزرو ناموفق / نقض سقف — نهایی)

OPEN
  ├─► PARTIALLY_FILLED      (اجرای جزئی)
  ├─► FILLED                (اجرای کامل — نهایی)
  ├─► CANCELLED             (لغو کاربر یا سیستم — نهایی)
  └─► EXPIRED               (پایان اعتبار — نهایی)

PARTIALLY_FILLED
  ├─► FILLED
  ├─► CANCELLED             (باقیمانده لغو می‌شود)
  └─► EXPIRED
```

<div dir="rtl">

### اثرات جانبی

| گذار | اثر |
|---|---|
| `PENDING → OPEN` | رزرو در دفتر ثبت شد |
| `PENDING → REJECTED` | بدون رزرو |
| `* → FILLED` | رزرو کاملاً به تسویه منتقل شد |
| `* → CANCELLED` | رزرو باقیمانده آزاد می‌شود |
| `* → EXPIRED` | رزرو باقیمانده آزاد می‌شود |
| `OPEN → PARTIALLY_FILLED` | بخشی از رزرو به تسویه منتقل شد |

---

## ۲.۴ Settlement

</div>

```
CREATED
  ├─► ASSETS_LOCKED
  └─► CANCELLED

ASSETS_LOCKED
  ├─► PAYMENT_PENDING       (مسیر عادی)
  ├─► NETTING_QUEUE         (مسیر تهاتر)
  ├─► CANCELLED
  └─► DISPUTED

PAYMENT_PENDING
  ├─► PAYMENT_DECLARED
  ├─► OVERDUE
  ├─► CANCELLED
  └─► DISPUTED

PAYMENT_DECLARED
  ├─► PAYMENT_CONFIRMED
  ├─► OVERDUE
  └─► DISPUTED

PAYMENT_CONFIRMED
  ├─► GOLD_TRANSFERRING
  └─► DISPUTED

GOLD_TRANSFERRING
  ├─► SETTLED
  └─► DISPUTED

SETTLED
  ├─► COMPLETED             (پس از پنجره اعتراض ۲۴ ساعته)
  ├─► DISPUTED
  └─► REVERSED

COMPLETED
  ├─► DISPUTED
  └─► REVERSED

OVERDUE
  ├─► PAYMENT_DECLARED      (تسویه دیرهنگام)
  ├─► DEFAULTED
  ├─► CANCELLED
  └─► DISPUTED

DEFAULTED
  ├─► SETTLED               (پس از اجرای وثیقه)
  ├─► CANCELLED
  └─► DISPUTED

NETTING_QUEUE
  ├─► SETTLED               (تهاتر اجرا شد)
  ├─► PAYMENT_PENDING       (تهاتر رد شد ► تسویه ناخالص)
  └─► CANCELLED

DISPUTED
  ├─► SETTLED               (اختلاف حل شد)
  ├─► REVERSED
  └─► CANCELLED

CANCELLED / REVERSED  ► نهایی
```

<div dir="rtl">

### اثرات جانبی مهم

| گذار | اثر روی دفتر |
|---|---|
| `CREATED → ASSETS_LOCKED` | RESERVED → IN_SETTLEMENT (طلا و ریال) |
| `PAYMENT_DECLARED → PAYMENT_CONFIRMED` | انتقال ریال + کارمزد |
| `GOLD_TRANSFERRING → SETTLED` | انتقال طلا + تغییر مالکیت lot |
| `* → CANCELLED` | IN_SETTLEMENT → AVAILABLE (آزادسازی) |
| `* → DISPUTED` | قفل مبلغ مورد اختلاف در IN_DISPUTE |
| `* → REVERSED` | ثبت entryهای معکوس |
| `NETTING_QUEUE → SETTLED` | ثبت خالص در یک transaction_group بزرگ |

---

## ۲.۵ GoldLot

</div>

```
UNDER_ASSAY
  └─► AVAILABLE             (گواهی صادر شد)

AVAILABLE
  ├─► RESERVED              (سفارش فروش یا درخواست برداشت)
  ├─► ON_HOLD               (توقف اداری)
  ├─► IN_TRANSIT            (شروع حمل)
  ├─► UNDER_ASSAY           (ارسال برای ری‌گیری مجدد)
  ├─► CONSUMED              (Split / Merge / Melt — نهایی)
  └─► WITHDRAWN             (خروج از سیستم)

RESERVED
  ├─► IN_SETTLEMENT         (معامله انجام شد)
  ├─► AVAILABLE             (سفارش لغو شد)
  └─► ON_HOLD

IN_SETTLEMENT
  ├─► AVAILABLE             (تسویه کامل — مالک عوض شد)
  ├─► RESERVED              (تسویه لغو شد)
  └─► ON_HOLD               (اختلاف)

IN_TRANSIT
  ├─► AVAILABLE             (رسید)
  └─► ON_HOLD               (مشکل در حمل)

ON_HOLD
  └─► AVAILABLE             (رفع توقف)

WITHDRAWN
  └─► AVAILABLE             (بازگشت به خزانه)

CONSUMED  ► نهایی (رکورد باقی می‌ماند برای شجره‌نامه)
```

<div dir="rtl">

---

## ۲.۶ RFQ

</div>

```
OPEN
  ├─► QUOTED                (اولین پیشنهاد رسید)
  ├─► CANCELLED             (لغو درخواست‌کننده — نهایی)
  └─► EXPIRED               (پایان مهلت — نهایی)

QUOTED
  ├─► ACCEPTED              (پیشنهاد پذیرفته شد)
  ├─► PARTIALLY_ACCEPTED    (پذیرش جزئی)
  ├─► CANCELLED
  └─► EXPIRED

PARTIALLY_ACCEPTED
  ├─► ACCEPTED              (تکمیل حجم)
  └─► EXPIRED

ACCEPTED  ► نهایی
```

<div dir="rtl">

### RfqQuote

</div>

```
PENDING
  ├─► ACCEPTED              (نهایی — معامله ایجاد شد)
  ├─► REJECTED              (نهایی)
  ├─► WITHDRAWN             (پیشنهاددهنده پس گرفت — نهایی)
  └─► EXPIRED               (نهایی)
```

<div dir="rtl">

---

## ۲.۷ OtcOffer

</div>

```
PENDING
  ├─► ACCEPTED              (نهایی)
  ├─► COUNTERED             (پیشنهاد متقابل)
  ├─► REJECTED              (نهایی)
  ├─► CANCELLED             (نهایی)
  └─► EXPIRED               (نهایی)

COUNTERED
  ├─► ACCEPTED
  ├─► COUNTERED             (حداکثر ۵ بار)
  ├─► REJECTED
  └─► EXPIRED
```

<div dir="rtl">

---

## ۲.۸ Dispute

</div>

```
OPENED
  ├─► AWAITING_REPLY        (خودکار)
  └─► WITHDRAWN             (نهایی)

AWAITING_REPLY
  ├─► ACCEPTED_BY_RESPONDENT
  ├─► NEGOTIATION           (رد یا پذیرش جزئی)
  ├─► UNDER_MEDIATION       (عدم پاسخ در مهلت)
  └─► WITHDRAWN

ACCEPTED_BY_RESPONDENT
  └─► RESOLVED

NEGOTIATION
  ├─► RESOLVED              (توافق)
  ├─► UNDER_MEDIATION       (عدم توافق در مهلت)
  └─► WITHDRAWN

UNDER_MEDIATION
  ├─► AWAITING_EVIDENCE     (درخواست مدرک تکمیلی)
  ├─► AWAITING_REASSAY      (ری‌گیری ثالث)
  └─► RESOLVED              (رأی صادر شد)

AWAITING_EVIDENCE / AWAITING_REASSAY
  └─► UNDER_MEDIATION

RESOLVED
  └─► EXECUTED              (نهایی)

WITHDRAWN / EXECUTED  ► نهایی
```

<div dir="rtl">

---

## ۲.۹ NettingBatch

</div>

```
PROPOSED
  ├─► ACCEPTING             (اولین پذیرش)
  └─► CANCELLED             (نهایی)

ACCEPTING
  ├─► EXECUTING             (همه پذیرفتند)
  └─► CANCELLED             (یکی رد کرد یا مهلت گذشت)

EXECUTING
  ├─► EXECUTED              (نهایی)
  └─► FAILED                (خطا در اجرا — نیاز به مداخله)

FAILED
  ├─► EXECUTING             (تلاش مجدد)
  └─► CANCELLED
```

<div dir="rtl">

---

## ۲.۱۰ MarketSession

</div>

```
SCHEDULED
  ├─► PRE_OPEN              (ساعت پیش‌گشایش)
  └─► CLOSED                (تعطیلی)

PRE_OPEN
  ├─► OPEN                  (حراج بازگشایی)
  └─► CLOSED                (لغو جلسه)

OPEN
  ├─► PAUSED                (Circuit Breaker یا توقف اضطراری)
  └─► CLOSED                (ساعت پایان)

PAUSED
  ├─► OPEN                  (بازگشایی با حراج)
  └─► CLOSED                (بستن زودهنگام)

CLOSED  ► نهایی برای آن روز
```

<div dir="rtl">

---

## ۲.۱۱ AmlFlag

</div>

```
OPEN
  └─► UNDER_REVIEW          (اختصاص به بررسی‌کننده)

UNDER_REVIEW
  ├─► CLEARED               (نهایی — بی‌مورد)
  ├─► FALSE_POSITIVE        (نهایی — قاعده نیاز به تنظیم دارد)
  ├─► ENHANCED_REVIEW       (نیاز به بررسی عمیق‌تر)
  └─► ESCALATED

ENHANCED_REVIEW
  ├─► CLEARED
  ├─► ACTION_TAKEN          (محدودسازی/تعلیق)
  └─► ESCALATED

ESCALATED
  ├─► ACTION_TAKEN
  └─► CLEARED

ACTION_TAKEN  ► نهایی
```

<div dir="rtl">

---

## ۲.۱۲ KycProfile

</div>

```
DRAFT
  └─► SUBMITTED

SUBMITTED
  ├─► IN_REVIEW
  └─► DRAFT                 (عضو ویرایش کرد)

IN_REVIEW
  ├─► APPROVED              (نهایی برای این دوره)
  ├─► INFO_REQUIRED
  └─► REJECTED              (نهایی)

INFO_REQUIRED
  └─► SUBMITTED

APPROVED
  └─► IN_REVIEW             (بازبینی دوره‌ای)
```

<div dir="rtl">

---

## ۲.۱۳ CustodyOperation

</div>

```
REQUESTED
  ├─► APPROVED
  ├─► REJECTED              (نهایی)
  └─► CANCELLED             (نهایی)

APPROVED
  ├─► EXECUTING
  └─► CANCELLED

EXECUTING
  ├─► COMPLETED             (نهایی)
  └─► FAILED

FAILED
  ├─► EXECUTING             (تلاش مجدد)
  └─► CANCELLED
```

<div dir="rtl">

---

## ۲.۱۴ قواعد کلی برای همه ماشین‌های حالت

</div>

```
۱) هر گذار باید در جدول رویداد ثبت شود
   (چه کسی، چه زمانی، چرا)

۲) هیچ گذاری بدون بررسی allowedTransitions انجام نمی‌شود

۳) وضعیت نهایی هرگز تغییر نمی‌کند
   ⚠️ استثنا: DISPUTED و REVERSED می‌توانند روی
      وضعیت‌های نهایی مالی اعمال شوند

۴) گذارهای خودکار (توسط سیستم) با actor_type = SYSTEM ثبت می‌شوند

۵) گذارهای نیازمند تأیید دوگانه، وضعیت میانی PENDING_APPROVAL دارند

۶) هر گذار که اثر مالی دارد، باید داخل تراکنش با
   نوشتن در دفتر انجام شود

۷) تست: برای هر ماشین حالت، تست جامع تمام گذارهای
   مجاز و رد تمام گذارهای غیرمجاز
```

<div dir="rtl">

### نمونه تست ماشین حالت

</div>

```php
/** @test */
public function settlement_rejects_all_invalid_transitions(): void
{
    foreach (SettlementStatus::cases() as $from) {
        foreach (SettlementStatus::cases() as $to) {
            $settlement = Settlement::factory()->create(['status' => $from->value]);

            if ($from->canTransitionTo($to)) {
                $this->stateMachine->transition($settlement, $to, $this->ctx());
                $this->assertSame($to->value, $settlement->fresh()->status);
            } else {
                $this->expectException(InvalidStateTransitionException::class);
                $this->stateMachine->transition($settlement, $to, $this->ctx());
            }
        }
    }
}
```

</div>
