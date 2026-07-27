<div dir="rtl">

# ۳. WebSocket و Webhook

# بخش الف — WebSocket

## ۳.۱ اتصال

از **Laravel Reverb** (پروتکل سازگار با Pusher) استفاده می‌شود.

</div>

```
wss://ws.goldb2b.ir/app/{app_key}
```

<div dir="rtl">

### احراز هویت کانال خصوصی

</div>

```
۱) کلاینت به WebSocket متصل می‌شود
۲) برای کانال خصوصی، درخواست مجوز می‌فرستد:

POST /api/v1/broadcasting/auth
Authorization: Bearer {token}

{
  "socket_id": "123.456",
  "channel_name": "private-org.184"
}

۳) سرور بررسی می‌کند که کاربر متعلق به سازمان ۱۸۴ باشد
۴) امضا برمی‌گرداند
۵) کلاینت با امضا subscribe می‌کند
```

<div dir="rtl">

---

## ۳.۲ کانال‌ها

### کانال‌های عمومی

| کانال | محتوا |
|---|---|
| `market.{instrument}` | قیمت، عمق بازار، معاملات |
| `market.status` | وضعیت جلسه معاملاتی، توقف بازار |
| `reference-price` | قیمت مرجع و اونس/ارز |

### کانال‌های خصوصی

| کانال | محتوا |
|---|---|
| `private-org.{orgId}` | سفارش‌ها، معاملات، موجودی |
| `private-org.{orgId}.settlement` | وضعیت تسویه‌ها |
| `private-org.{orgId}.rfq` | RFQ و پیشنهادها |
| `private-org.{orgId}.notification` | اعلان‌های زنده |
| `private-user.{userId}` | رویدادهای شخصی کاربر |

### کانال ادمین

| کانال | محتوا |
|---|---|
| `private-admin.monitoring` | جریان زنده معاملات و هشدارها |
| `private-admin.alerts` | هشدارهای بحرانی |

---

## ۳.۳ رویدادهای WebSocket

### `market.{instrument}`

</div>

```json
// رویداد: quote.updated
{
  "event": "quote.updated",
  "channel": "market.GOLD-995-T0",
  "data": {
    "instrument": "GOLD-995-T0",
    "best_bid": 78420000,
    "best_bid_qty_mg": 300000,
    "best_ask": 78480000,
    "best_ask_qty_mg": 350000,
    "last_price": 78450000,
    "spread": 60000,
    "day_change_bps": 42,
    "timestamp": "2026-07-27T09:15:33.412Z"
  }
}
```

```json
// رویداد: depth.updated  (تجمیع‌شده، حداکثر ۱۰/ثانیه)
{
  "event": "depth.updated",
  "data": {
    "instrument": "GOLD-995-T0",
    "bids": [[78420000, 300000, 2], [78400000, 250000, 1]],
    "asks": [[78480000, 350000, 2], [78500000, 500000, 1]],
    "timestamp": "2026-07-27T09:15:33.412Z"
  }
}
```

```json
// رویداد: trade.executed  (بدون هویت طرفین)
{
  "event": "trade.executed",
  "data": {
    "instrument": "GOLD-995-T0",
    "price_rial": 78480000,
    "quantity_mg": 100000,
    "taker_side": "BUY",
    "executed_at": "2026-07-27T09:15:33.480Z"
  }
}
```

<div dir="rtl">

> ⚠️ کانال عمومی **هرگز** `organization_id`، نام عضو یا هر شناسه‌ای که
> بتوان از آن هویت را استنتاج کرد منتشر نمی‌کند.

### `private-org.{orgId}`

</div>

```json
// رویداد: order.updated
{
  "event": "order.updated",
  "data": {
    "id": 44120,
    "order_code": "ORD-00044120",
    "status": "PARTIALLY_FILLED",
    "filled_mg": 100000,
    "remaining_mg": 150000,
    "last_fill": {
      "trade_code": "TRD-00088231",
      "quantity_mg": 100000,
      "price_rial": 78480000
    }
  }
}
```

```json
// رویداد: balance.updated
{
  "event": "balance.updated",
  "data": {
    "asset_type": "GOLD",
    "available_mg": 947320,
    "reserved_mg": 250000,
    "in_settlement_mg": 50000,
    "total_mg": 1247320,
    "trigger": "order.placed",
    "reference": "ORD-00044120"
  }
}
```

```json
// رویداد: trade.executed  (نسخه کامل برای طرفین)
{
  "event": "trade.executed",
  "data": {
    "trade_code": "TRD-00088231",
    "side": "BUY",
    "counterparty": {
      "id": 291,
      "display_name": "بنکداری پارس",
      "verification_tier": "GOLD"
    },
    "quantity_fine_mg": 100000,
    "price_per_gram_rial": 78480000,
    "gross_amount_rial": 7848000000,
    "fee_rial": 11772000,
    "net_amount_rial": 7859772000,
    "settlement_code": "STL-00088231",
    "settlement_deadline": "2026-07-27T17:00:00Z"
  }
}
```

<div dir="rtl">

### `private-org.{orgId}.settlement`

</div>

```json
{
  "event": "settlement.status_changed",
  "data": {
    "settlement_code": "STL-00088231",
    "from_status": "PAYMENT_PENDING",
    "to_status": "PAYMENT_DECLARED",
    "requires_your_action": true,
    "action_type": "CONFIRM_PAYMENT",
    "deadline_at": "2026-07-27T17:00:00Z",
    "payment_reference": "987654321"
  }
}
```

<div dir="rtl">

---

## ۳.۴ مدیریت اتصال در کلاینت

</div>

```
اتصال اولیه
     │
     ▼
Subscribe به کانال‌های لازم
     │
     ▼
دریافت رویدادها
     │
     ├── قطع اتصال؟
     │      │
     │      ▼
     │   Reconnect با backoff نمایی (1s, 2s, 4s, 8s, max 30s)
     │      │
     │      ▼
     │   ⚠️ REST را برای همگام‌سازی وضعیت فراخوانی کن
     │      (WebSocket تضمین تحویل نمی‌دهد)
     │      │
     │      ▼
     │   Subscribe مجدد
     │
     ▼
```

<div dir="rtl">

**قاعده حیاتی:** WebSocket برای **به‌روزرسانی** است، نه برای **حقیقت**.
وضعیت واقعی همیشه از REST خوانده می‌شود. پس از هر reconnect، کلاینت باید
موجودی و سفارش‌های باز را مجدداً بارگیری کند.

---

## ۳.۵ Throttle و بهینه‌سازی

</div>

```
· depth.updated       : حداکثر ۱۰ بار در ثانیه، تجمیع تغییرات
· quote.updated       : حداکثر ۵ بار در ثانیه
· trade.executed      : بدون throttle (هر معامله)
· balance.updated     : بدون throttle (مهم است)
· notification        : بدون throttle

حجم پیام:
  · فقط فیلدهای تغییریافته در به‌روزرسانی‌های جزئی
  · بدون فیلدهای _display در WebSocket (کلاینت خودش فرمت می‌کند)
  · فشرده‌سازی permessage-deflate فعال
```

<div dir="rtl">

---

# بخش ب — Webhook

## ۳.۶ کاربرد

اتصال نرم‌افزار حسابداری عضو به سامانه، بدون نیاز به polling.

</div>

```
Gold B2B                          نرم‌افزار حسابداری عضو
    │                                        │
    │  رویداد رخ داد                          │
    │                                        │
    ├──── POST https://member.local/hook ───►│
    │      X-GoldB2B-Signature: ...          │
    │      X-GoldB2B-Event: trade.executed   │
    │                                        │
    │◄──── 200 OK ───────────────────────────┤
    │                                        │
    │  اگر non-2xx یا timeout:               │
    │  retry با backoff نمایی                │
```

<div dir="rtl">

---

## ۳.۷ ثبت Webhook

</div>

```http
POST /api/v1/webhooks
Authorization: Bearer {token}

{
  "url": "https://accounting.example.ir/goldb2b/hook",
  "events": [
    "trade.executed",
    "settlement.completed",
    "balance.updated",
    "lot.ownership_transferred"
  ],
  "description": "اتصال نرم‌افزار حسابداری"
}
```

```json
{
  "data": {
    "id": 12,
    "url": "https://accounting.example.ir/goldb2b/hook",
    "events": ["trade.executed", "..."],
    "secret": "whsec_a3f9c2b1...",
    "status": "ACTIVE",
    "created_at": "2026-07-27T09:00:00Z"
  },
  "meta": {
    "warning": "این تنها بار نمایش secret است. آن را ذخیره کنید."
  }
}
```

<div dir="rtl">

---

## ۳.۸ ساختار Payload

</div>

```json
{
  "id": "evt_a3f9c2b14d5e6f7a",
  "type": "trade.executed",
  "api_version": "v1",
  "created_at": "2026-07-27T09:15:33.480Z",
  "organization_id": 184,
  "data": {
    "trade_code": "TRD-00088231",
    "side": "SELL",
    "instrument": "GOLD-995-T0",
    "counterparty": {
      "id": 291,
      "display_name": "بنکداری پارس"
    },
    "quantity_fine_mg": 250000,
    "price_per_gram_rial": 78480000,
    "gross_amount_rial": 19620000000,
    "fee_rial": 19620000,
    "net_amount_rial": 19600380000,
    "settlement_code": "STL-00088231",
    "settlement_deadline": "2026-07-27T17:00:00Z",
    "executed_at": "2026-07-27T09:15:33.480Z"
  }
}
```

<div dir="rtl">

---

## ۳.۹ امضا و اعتبارسنجی

### هدرها

</div>

```http
X-GoldB2B-Signature: t=1735689600,v1=5257a869e7ecebeda32affa62cdca3fa51cad7e77a0e56ff536d0ce8e108d8bd
X-GoldB2B-Event: trade.executed
X-GoldB2B-Event-Id: evt_a3f9c2b14d5e6f7a
X-GoldB2B-Delivery-Attempt: 1
```

<div dir="rtl">

### محاسبه امضا

</div>

```
signed_payload = "{timestamp}.{raw_request_body}"
signature = HMAC_SHA256(webhook_secret, signed_payload)
```

<div dir="rtl">

### نمونه اعتبارسنجی (PHP)

</div>

```php
function verifyWebhook(string $payload, string $header, string $secret): bool
{
    // پارس هدر
    $parts = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = explode('=', $part, 2);
        $parts[$k] = $v;
    }

    $timestamp = (int) ($parts['t'] ?? 0);
    $signature = $parts['v1'] ?? '';

    // ۱) بررسی پنجره زمانی — جلوگیری از replay
    if (abs(time() - $timestamp) > 300) {
        return false;
    }

    // ۲) محاسبه و مقایسه زمان‌ثابت
    $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return hash_equals($expected, $signature);
}
```

<div dir="rtl">

---

## ۳.۱۰ تلاش مجدد

</div>

```
تلاش    تأخیر
──────────────────
  ۱     فوری
  ۲     ۳۰ ثانیه
  ۳     ۲ دقیقه
  ۴     ۱۰ دقیقه
  ۵     ۱ ساعت
  ۶     ۶ ساعت
  ۷     ۲۴ ساعت
──────────────────
پس از ۷ تلاش ناموفق:
  ► webhook در وضعیت FAILING قرار می‌گیرد
  ► اعلان به عضو
  ► پس از ۷۲ ساعت وضعیت DISABLED
```

<div dir="rtl">

**timeout هر تلاش:** ۱۰ ثانیه.
**پاسخ موفق:** هر کد ۲xx.

---

## ۳.۱۱ Idempotency در سمت گیرنده

سامانه ممکن است یک رویداد را **بیش از یک بار** ارسال کند
(مثلاً وقتی پاسخ گیرنده به دلیل timeout شبکه دریافت نشود).

</div>

```php
// در سمت گیرنده
$eventId = $request->header('X-GoldB2B-Event-Id');

if (ProcessedWebhook::where('event_id', $eventId)->exists()) {
    return response()->noContent();   // قبلاً پردازش شده
}

DB::transaction(function () use ($eventId, $payload) {
    ProcessedWebhook::create(['event_id' => $eventId]);
    // پردازش...
});
```

<div dir="rtl">

---

## ۳.۱۲ فهرست رویدادهای Webhook

</div>

```
معاملات
  trade.executed
  order.filled
  order.partially_filled
  order.cancelled
  order.rejected

تسویه
  settlement.opened
  settlement.payment_declared
  settlement.payment_confirmed
  settlement.completed
  settlement.overdue
  settlement.cancelled
  settlement.reversed

دفتر
  balance.updated
  ledger.entry_created

طلای فیزیکی
  lot.created
  lot.ownership_transferred
  lot.split
  lot.merged
  lot.assay_recorded
  lot.deposited
  lot.withdrawn

RFQ / OTC
  rfq.received
  rfq.quoted
  rfq.accepted
  otc.offer_received
  otc.offer_accepted

حساب
  organization.status_changed
  license.expiring
  limit.changed

اختلاف
  dispute.opened
  dispute.resolved

تهاتر
  netting.proposed
  netting.executed
```

<div dir="rtl">

---

## ۳.۱۳ مدیریت و اشکال‌زدایی

| متد | مسیر | شرح |
|---|---|---|
| `GET` | `/webhooks` | فهرست webhookهای من |
| `POST` | `/webhooks` | ثبت |
| `PUT` | `/webhooks/{id}` | ویرایش رویدادها |
| `DELETE` | `/webhooks/{id}` | حذف |
| `POST` | `/webhooks/{id}/rotate-secret` | چرخش کلید |
| `POST` | `/webhooks/{id}/test` | ارسال رویداد آزمایشی |
| `GET` | `/webhooks/{id}/deliveries` | تاریخچه ارسال |
| `POST` | `/webhook-deliveries/{id}/retry` | تلاش مجدد دستی |

</div>

```json
// GET /webhooks/12/deliveries
{
  "data": [
    {
      "id": 8821,
      "event_id": "evt_a3f9c2b14d5e6f7a",
      "event_type": "trade.executed",
      "status": "DELIVERED",
      "attempts": 1,
      "response_code": 200,
      "response_time_ms": 142,
      "delivered_at": "2026-07-27T09:15:34.100Z"
    },
    {
      "id": 8820,
      "event_type": "settlement.completed",
      "status": "FAILED",
      "attempts": 7,
      "response_code": 500,
      "last_error": "Internal Server Error",
      "next_retry_at": null
    }
  ]
}
```

</div>
