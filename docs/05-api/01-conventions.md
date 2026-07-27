<div dir="rtl">

# ۱. قراردادهای API

## ۱.۱ اصول

</div>

```
· REST روی HTTPS، JSON در بدنه
· نسخه‌بندی در مسیر: /api/v1/...
· احراز هویت: Bearer Token (Sanctum) یا API Key + HMAC
· همه تاریخ‌ها ISO-8601 با UTC: 2026-07-27T09:15:33.412Z
· همه مبالغ و وزن‌ها عدد صحیح (بدون اعشار، بدون رشته)
· snake_case برای نام فیلدها
· صفحه‌بندی مبتنی بر cursor برای فهرست‌های بزرگ
```

---

## ۱.۲ پایه URL

</div>

```
Production : https://api.goldb2b.ir/api/v1
Sandbox    : https://sandbox-api.goldb2b.ir/api/v1
WebSocket  : wss://ws.goldb2b.ir/app/{key}
```

<div dir="rtl">

---

## ۱.۳ هدرهای استاندارد

### درخواست

</div>

```http
Authorization:      Bearer {token}
Content-Type:       application/json
Accept:             application/json
Accept-Language:    fa
X-Request-Id:       {uuid}              اختیاری، برای ردیابی
Idempotency-Key:    {uuid}              اجباری برای POST/PUT مالی
X-Client-Version:   flutter/1.2.3       اختیاری
```

<div dir="rtl">

### پاسخ

</div>

```http
Content-Type:            application/json
X-Request-Id:            {uuid}         بازتاب یا تولیدشده
X-RateLimit-Limit:       60
X-RateLimit-Remaining:   57
X-RateLimit-Reset:       1735689600
X-Response-Time:         42ms
```

<div dir="rtl">

---

## ۱.۴ قالب پاسخ موفق

### تک منبع

</div>

```json
{
  "data": {
    "id": 44120,
    "order_code": "ORD-00044120",
    "side": "BUY",
    "quantity_mg": 250000,
    "price_rial": 78510000,
    "status": "OPEN",
    "placed_at": "2026-07-27T09:15:33.412Z"
  },
  "meta": {
    "request_id": "0f9c2b3a-4d5e-6f7a-8b9c-0d1e2f3a4b5c",
    "server_time": "2026-07-27T09:15:33.500Z"
  }
}
```

<div dir="rtl">

### فهرست با صفحه‌بندی

</div>

```json
{
  "data": [
    { "id": 44120, "...": "..." },
    { "id": 44119, "...": "..." }
  ],
  "meta": {
    "request_id": "...",
    "server_time": "...",
    "count": 2
  },
  "links": {
    "next": "/api/v1/orders?cursor=eyJpZCI6NDQxMTl9&limit=50",
    "prev": null
  }
}
```

<div dir="rtl">

---

## ۱.۵ قالب خطا

</div>

```json
{
  "error": {
    "code": "INSUFFICIENT_BALANCE",
    "message": "موجودی طلای شما برای این سفارش کافی نیست.",
    "message_en": "Insufficient gold balance for this order.",
    "details": {
      "required_mg": 250000,
      "available_mg": 180000,
      "shortfall_mg": 70000
    },
    "field_errors": null,
    "documentation_url": "https://docs.goldb2b.ir/errors/INSUFFICIENT_BALANCE"
  },
  "meta": {
    "request_id": "0f9c2b3a-4d5e-6f7a-8b9c-0d1e2f3a4b5c",
    "server_time": "2026-07-27T09:15:33.500Z"
  }
}
```

<div dir="rtl">

### خطای اعتبارسنجی

</div>

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "داده ورودی نامعتبر است.",
    "field_errors": {
      "quantity_mg": ["حداقل مقدار سفارش ۵۰ گرم است."],
      "price_rial": ["قیمت باید مضربی از ۱۰,۰۰۰ ریال باشد."]
    }
  }
}
```

<div dir="rtl">

---

## ۱.۶ کدهای وضعیت HTTP

| کد | معنی | مثال |
|---|---|---|
| `200` | موفق | دریافت اطلاعات |
| `201` | ایجاد شد | ثبت سفارش |
| `202` | پذیرفته شد، در حال پردازش | تولید گزارش سنگین |
| `204` | موفق بدون محتوا | لغو سفارش |
| `400` | درخواست نامعتبر | JSON خراب |
| `401` | احراز هویت نشده | توکن منقضی |
| `403` | مجاز نیست | نقش ناکافی |
| `404` | یافت نشد | سفارش وجود ندارد |
| `409` | تعارض | Idempotency در حال پردازش |
| `410` | منقضی | RFQ منقضی شده |
| `422` | داده نامعتبر یا قاعده کسب‌وکار نقض شده | موجودی ناکافی |
| `423` | قفل شده | حساب معلق |
| `429` | تعداد درخواست زیاد | Rate Limit |
| `500` | خطای سرور | — |
| `503` | سرویس در دسترس نیست | بازار بسته/تعمیرات |

---

## ۱.۷ کدهای خطای کسب‌وکار

### احراز هویت و دسترسی

</div>

```
AUTH_INVALID_CREDENTIALS      نام کاربری یا رمز اشتباه
AUTH_TOKEN_EXPIRED            توکن منقضی شده
AUTH_TOKEN_INVALID            توکن نامعتبر
AUTH_2FA_REQUIRED             نیاز به عامل دوم
AUTH_2FA_INVALID              کد عامل دوم اشتباه
AUTH_ACCOUNT_LOCKED           حساب قفل شده
AUTH_TRANSACTION_SIGN_REQUIRED نیاز به تأیید تراکنش
FORBIDDEN_ROLE                نقش شما این عملیات را مجاز نمی‌کند
FORBIDDEN_TENANT              این منبع متعلق به سازمان شما نیست
FORBIDDEN_DUAL_CONTROL        نیاز به تأیید کاربر دوم
```

<div dir="rtl">

### وضعیت سازمان

</div>

```
ORG_NOT_ACTIVE                حساب شما فعال نیست
ORG_SUSPENDED                 حساب شما معلق است
ORG_RESTRICTED                حساب شما محدود است
ORG_LICENSE_EXPIRED           مجوز کسب شما منقضی شده
ORG_KYC_INCOMPLETE            احراز هویت تکمیل نشده
```

<div dir="rtl">

### معاملات

</div>

```
MARKET_CLOSED                 بازار بسته است
MARKET_PAUSED                 بازار موقتاً متوقف است
INSTRUMENT_NOT_ACTIVE         این ابزار فعال نیست
ORDER_QUANTITY_TOO_SMALL      حجم کمتر از حداقل
ORDER_QUANTITY_TOO_LARGE      حجم بیشتر از حداکثر
ORDER_PRICE_INVALID_TICK      قیمت مضرب گام قیمتی نیست
ORDER_PRICE_OUT_OF_RANGE      قیمت خارج از بازه مجاز
ORDER_NOT_FOUND               سفارش یافت نشد
ORDER_NOT_CANCELLABLE         سفارش قابل لغو نیست
SELF_TRADE_PREVENTED          معامله با خود مجاز نیست
```

<div dir="rtl">

### دفتر و موجودی

</div>

```
INSUFFICIENT_BALANCE          موجودی کافی نیست
INSUFFICIENT_GOLD             موجودی طلا کافی نیست
INSUFFICIENT_RIAL             موجودی ریالی کافی نیست
BALANCE_LOCKED                موجودی قفل است
LEDGER_ACCOUNT_FROZEN         حساب دفتر منجمد است
```

<div dir="rtl">

### ریسک

</div>

```
LIMIT_PER_ORDER_EXCEEDED      سقف هر سفارش نقض شد
LIMIT_DAILY_VOLUME_EXCEEDED   سقف حجم روزانه نقض شد
LIMIT_OPEN_ORDERS_EXCEEDED    تعداد سفارش باز بیش از حد
LIMIT_EXPOSURE_EXCEEDED       سقف تعهدات باز نقض شد
LIMIT_COUNTERPARTY_EXCEEDED   سقف طرف‌حساب نقض شد
SETTLEMENT_TYPE_NOT_ALLOWED   این نوع تسویه برای شما مجاز نیست
TRADING_NOT_ALLOWED           معامله برای شما مجاز نیست
```

<div dir="rtl">

### تسویه

</div>

```
SETTLEMENT_NOT_FOUND          تسویه یافت نشد
SETTLEMENT_WRONG_STATE        وضعیت تسویه اجازه این عملیات را نمی‌دهد
SETTLEMENT_NOT_YOUR_TURN      نوبت اقدام شما نیست
SETTLEMENT_ALREADY_CONFIRMED  قبلاً تأیید شده
SETTLEMENT_OVERDUE            مهلت تسویه گذشته
```

<div dir="rtl">

### خزانه

</div>

```
LOT_NOT_FOUND                 lot یافت نشد
LOT_NOT_AVAILABLE             lot در دسترس نیست
LOT_NOT_OWNED                 مالک این lot نیستید
LOT_ON_HOLD                   lot در وضعیت توقف است
VAULT_WITHDRAWAL_BLOCKED      برداشت به دلیل تعهد باز مسدود است
ASSAY_REQUIRED                نیاز به گواهی ری‌گیری
```

<div dir="rtl">

### عمومی

</div>

```
VALIDATION_FAILED             خطای اعتبارسنجی
IDEMPOTENCY_IN_PROGRESS       درخواست مشابه در حال پردازش
IDEMPOTENCY_KEY_REUSED        کلید تکراری با محتوای متفاوت
RATE_LIMIT_EXCEEDED           تعداد درخواست بیش از حد
RESOURCE_NOT_FOUND            منبع یافت نشد
INTERNAL_ERROR                خطای داخلی
SERVICE_UNAVAILABLE           سرویس در دسترس نیست
```

<div dir="rtl">

---

## ۱.۸ صفحه‌بندی

### Cursor-based (پیش‌فرض برای فهرست‌های بزرگ)

</div>

```http
GET /api/v1/trades?limit=50&cursor=eyJpZCI6ODgyMzF9
```

<div dir="rtl">

- `cursor` رمزگذاری base64 از `{"id": 88231}` است
- پایدار در برابر درج رکورد جدید
- `limit` پیش‌فرض ۵۰، حداکثر ۲۰۰

### Offset-based (فقط برای فهرست‌های کوچک)

</div>

```http
GET /api/v1/bank-accounts?page=2&per_page=20
```

<div dir="rtl">

---

## ۱.۹ فیلتر و مرتب‌سازی

</div>

```http
GET /api/v1/trades
    ?filter[side]=BUY
    &filter[instrument]=GOLD-995-T0
    &filter[executed_from]=2026-07-01T00:00:00Z
    &filter[executed_to]=2026-07-27T23:59:59Z
    &filter[min_quantity_mg]=100000
    &sort=-executed_at
    &limit=50
```

<div dir="rtl">

- `sort` با `-` برای نزولی
- چند مرتب‌سازی: `sort=-executed_at,quantity_mg`
- فیلترهای مجاز برای هر endpoint در مستندات آن مشخص است

---

## ۱.۱۰ Idempotency

اجباری برای این عملیات:

</div>

```
POST /orders
POST /orders/{id}/cancel
POST /otc-offers
POST /otc-offers/{id}/accept
POST /rfqs
POST /rfqs/{id}/quotes
POST /rfq-quotes/{id}/accept
POST /settlements/{id}/declare-payment
POST /settlements/{id}/confirm-payment
POST /vault/deposits
POST /vault/withdrawals
POST /lots/{id}/split
POST /netting-batches/{id}/accept
```

<div dir="rtl">

</div>

```http
POST /api/v1/orders
Idempotency-Key: 0f9c2b3a-4d5e-6f7a-8b9c-0d1e2f3a4b5c
```

<div dir="rtl">

**رفتار:**

| وضعیت | پاسخ |
|---|---|
| کلید جدید | اجرا، ذخیره نتیجه، `201` |
| تکراری، همان بدنه، تکمیل‌شده | نتیجه ذخیره‌شده، `200` + هدر `X-Idempotent-Replay: true` |
| تکراری، همان بدنه، در حال پردازش | `409 IDEMPOTENCY_IN_PROGRESS` |
| تکراری، بدنه متفاوت | `422 IDEMPOTENCY_KEY_REUSED` |

اعتبار کلید: ۲۴ ساعت.

---

## ۱.۱۱ نسخه‌بندی و منسوخ‌سازی

</div>

```
تغییرات غیرشکننده (بدون نسخه جدید):
  · افزودن فیلد جدید به پاسخ
  · افزودن پارامتر اختیاری
  · افزودن endpoint جدید
  · افزودن مقدار جدید به enum پاسخ  ⚠️ کلاینت باید تحمل کند

تغییرات شکننده (نسخه جدید لازم):
  · حذف یا تغییر نام فیلد
  · تغییر نوع فیلد
  · تغییر معنای فیلد
  · اجباری کردن پارامتر اختیاری
  · حذف مقدار enum
```

<div dir="rtl">

### اعلام منسوخ‌سازی

</div>

```http
HTTP/1.1 200 OK
Deprecation: true
Sunset: Sat, 31 Dec 2027 23:59:59 GMT
Link: <https://docs.goldb2b.ir/migration/v2>; rel="deprecation"
Warning: 299 - "This endpoint is deprecated. Migrate to /api/v2/orders"
```

<div dir="rtl">

پشتیبانی از نسخه قبلی: حداقل ۱۲ ماه پس از انتشار نسخه جدید.

---

## ۱.۱۲ نمایش مقادیر

</div>

```json
{
  "quantity_mg": 250000,
  "quantity_display": "250.000 گرم",

  "price_rial": 78510000,
  "price_display": "۷۸,۵۱۰,۰۰۰ ریال",

  "purity_x10": 9950,
  "purity_display": "۹۹۵",

  "executed_at": "2026-07-27T09:15:33.412Z",
  "executed_at_jalali": "۱۴۰۵/۰۵/۰۵ ۱۲:۴۵:۳۳"
}
```

<div dir="rtl">

**قاعده:** فیلدهای `_display` و `_jalali` فقط برای راحتی کلاینت هستند.
هیچ محاسبه‌ای نباید روی آن‌ها انجام شود. مقدار عددی همیشه مبنا است.

فیلدهای نمایشی با `?include_display=false` قابل حذف هستند (کاهش حجم).

</div>
