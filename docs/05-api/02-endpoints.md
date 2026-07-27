<div dir="rtl">

# ۲. فهرست Endpointها

`🔒` = نیاز به احراز هویت · `👑` = نیاز به نقش خاص · `🔑` = نیاز به Idempotency-Key
`✍️` = نیاز به تأیید تراکنش (TOTP)

---

## ۲.۱ احراز هویت

| متد | مسیر | شرح |
|---|---|---|
| `POST` | `/auth/register` | ثبت‌نام اولیه با موبایل |
| `POST` | `/auth/otp/send` | ارسال کد یکبارمصرف |
| `POST` | `/auth/otp/verify` | تأیید کد |
| `POST` | `/auth/login` | ورود |
| `POST` | `/auth/login/2fa` | تأیید عامل دوم |
| `POST` | `/auth/refresh` | تازه‌سازی توکن |
| `POST` | `/auth/logout` | 🔒 خروج |
| `POST` | `/auth/logout-all` | 🔒 خروج از همه دستگاه‌ها |
| `POST` | `/auth/password/forgot` | فراموشی رمز |
| `POST` | `/auth/password/reset` | بازنشانی رمز |
| `PUT` | `/auth/password` | 🔒 تغییر رمز |
| `GET` | `/auth/sessions` | 🔒 فهرست نشست‌های فعال |
| `DELETE` | `/auth/sessions/{id}` | 🔒 ابطال نشست |
| `POST` | `/auth/2fa/enable` | 🔒 فعال‌سازی 2FA |
| `POST` | `/auth/2fa/confirm` | 🔒 تأیید 2FA |
| `DELETE` | `/auth/2fa` | 🔒 ✍️ غیرفعال‌سازی |

### نمونه: ثبت سفارش

</div>

```http
POST /api/v1/auth/login
Content-Type: application/json

{
  "mobile": "09123456789",
  "password": "..."
}
```

<div dir="rtl">

</div>

```json
{
  "data": {
    "requires_2fa": true,
    "challenge_token": "chg_a3f9...",
    "methods": ["TOTP", "SMS"]
  }
}
```

<div dir="rtl">

</div>

```http
POST /api/v1/auth/login/2fa

{
  "challenge_token": "chg_a3f9...",
  "method": "TOTP",
  "code": "123456"
}
```

<div dir="rtl">

</div>

```json
{
  "data": {
    "access_token": "...",
    "refresh_token": "...",
    "token_type": "Bearer",
    "expires_in": 900,
    "user": {
      "id": 512,
      "full_name": "حسن رضایی",
      "roles": ["TRADER"],
      "permissions": ["order.create", "order.cancel.own", "ledger.view"]
    },
    "organization": {
      "id": 184,
      "display_name": "طلافروشی کریمی",
      "status": "ACTIVE",
      "verification_tier": "SILVER"
    }
  }
}
```

<div dir="rtl">

---

## ۲.۲ سازمان و پروفایل

| متد | مسیر | شرح |
|---|---|---|
| `GET` | `/organization` | 🔒 اطلاعات سازمان من |
| `PUT` | `/organization` | 🔒 👑OWNER ویرایش |
| `GET` | `/organization/kyc` | 🔒 وضعیت KYC |
| `POST` | `/organization/kyc/submit` | 🔒 👑OWNER ارسال برای بررسی |
| `GET` | `/organization/documents` | 🔒 فهرست اسناد |
| `POST` | `/organization/documents` | 🔒 آپلود سند |
| `DELETE` | `/organization/documents/{id}` | 🔒 حذف سند تأییدنشده |
| `GET` | `/organization/licenses` | 🔒 مجوزها |
| `POST` | `/organization/licenses` | 🔒 👑OWNER ثبت مجوز |
| `GET` | `/organization/bank-accounts` | 🔒 حساب‌های بانکی |
| `POST` | `/organization/bank-accounts` | 🔒 👑OWNER ✍️ افزودن |
| `DELETE` | `/organization/bank-accounts/{id}` | 🔒 👑OWNER ✍️ حذف |
| `GET` | `/organization/representatives` | 🔒 نمایندگان |
| `POST` | `/organization/representatives` | 🔒 👑OWNER افزودن |
| `GET` | `/organization/users` | 🔒 کاربران سازمان |
| `POST` | `/organization/users` | 🔒 👑OWNER دعوت کاربر |
| `PUT` | `/organization/users/{id}/roles` | 🔒 👑OWNER تغییر نقش |
| `PUT` | `/organization/users/{id}/limits` | 🔒 👑OWNER سقف کاربر |
| `DELETE` | `/organization/users/{id}` | 🔒 👑OWNER غیرفعال‌سازی |

---

## ۲.۳ دفتر کل و موجودی

| متد | مسیر | شرح |
|---|---|---|
| `GET` | `/balances` | 🔒 خلاصه موجودی طلا و ریال |
| `GET` | `/balances/gold` | 🔒 تفکیک موجودی طلا |
| `GET` | `/balances/rial` | 🔒 تفکیک موجودی ریال |
| `GET` | `/ledger/gold` | 🔒 دفتر کل طلا (صفحه‌بندی) |
| `GET` | `/ledger/rial` | 🔒 دفتر کل ریال |
| `GET` | `/ledger/entries/{id}` | 🔒 جزئیات یک ثبت |

</div>

```http
GET /api/v1/balances
```

```json
{
  "data": {
    "gold": {
      "metal_type": "GOLD",
      "available_mg": 1047320,
      "reserved_mg": 150000,
      "in_settlement_mg": 50000,
      "in_dispute_mg": 0,
      "total_mg": 1247320,
      "available_display": "۱,۰۴۷.۳۲۰ گرم",
      "total_display": "۱,۲۴۷.۳۲۰ گرم",
      "market_value_rial": 97910000000
    },
    "rial": {
      "available": 4200000000,
      "reserved": 1500000000,
      "in_settlement": 800000000,
      "in_dispute": 0,
      "payable": -300000000,
      "net": 6200000000
    },
    "as_of": "2026-07-27T09:15:33.412Z"
  }
}
```

<div dir="rtl">

---

## ۲.۴ بازار و قیمت

| متد | مسیر | شرح |
|---|---|---|
| `GET` | `/instruments` | 🔒 فهرست ابزارها |
| `GET` | `/instruments/{code}` | 🔒 جزئیات ابزار |
| `GET` | `/market/quotes` | 🔒 قیمت لحظه‌ای همه ابزارها |
| `GET` | `/market/quotes/{code}` | 🔒 قیمت یک ابزار |
| `GET` | `/market/depth/{code}` | 🔒 عمق بازار |
| `GET` | `/market/trades/{code}` | 🔒 نوار معاملات |
| `GET` | `/market/candles/{code}` | 🔒 داده شمعی |
| `GET` | `/market/sessions/{code}` | 🔒 وضعیت جلسه معاملاتی |
| `GET` | `/market/reference-price` | 🔒 قیمت مرجع و ارزش ذاتی |
| `GET` | `/price-alerts` | 🔒 هشدارهای من |
| `POST` | `/price-alerts` | 🔒 ایجاد هشدار |
| `DELETE` | `/price-alerts/{id}` | 🔒 حذف |

</div>

```http
GET /api/v1/market/depth/GOLD-995-T0?levels=10
```

```json
{
  "data": {
    "instrument": "GOLD-995-T0",
    "bids": [
      { "price_rial": 78420000, "quantity_mg": 300000, "order_count": 2 },
      { "price_rial": 78400000, "quantity_mg": 250000, "order_count": 1 }
    ],
    "asks": [
      { "price_rial": 78480000, "quantity_mg": 350000, "order_count": 2 },
      { "price_rial": 78500000, "quantity_mg": 500000, "order_count": 1 }
    ],
    "spread_rial": 60000,
    "mid_price_rial": 78450000,
    "as_of": "2026-07-27T09:15:33.412Z"
  }
}
```

<div dir="rtl">

---

## ۲.۵ سفارش‌ها

| متد | مسیر | شرح |
|---|---|---|
| `GET` | `/orders` | 🔒 فهرست سفارش‌های من |
| `GET` | `/orders/{id}` | 🔒 جزئیات |
| `POST` | `/orders` | 🔒 🔑 ثبت سفارش |
| `POST` | `/orders/{id}/cancel` | 🔒 🔑 لغو |
| `POST` | `/orders/cancel-all` | 🔒 🔑 لغو همه |
| `GET` | `/orders/{id}/fills` | 🔒 اجراهای سفارش |

</div>

```http
POST /api/v1/orders
Idempotency-Key: 0f9c2b3a-4d5e-6f7a-8b9c-0d1e2f3a4b5c

{
  "instrument": "GOLD-995-T0",
  "side": "BUY",
  "type": "LIMIT",
  "time_in_force": "DAY",
  "quantity_mg": 250000,
  "price_rial": 78510000
}
```

```json
{
  "data": {
    "id": 44120,
    "order_code": "ORD-00044120",
    "instrument": "GOLD-995-T0",
    "side": "BUY",
    "type": "LIMIT",
    "time_in_force": "DAY",
    "quantity_mg": 250000,
    "filled_mg": 100000,
    "remaining_mg": 150000,
    "price_rial": 78510000,
    "status": "PARTIALLY_FILLED",
    "reserved_rial": 19656941250,
    "placed_at": "2026-07-27T09:15:33.412Z",
    "expires_at": "2026-07-27T14:00:00.000Z",
    "fills": [
      {
        "trade_code": "TRD-00088231",
        "quantity_mg": 100000,
        "price_rial": 78480000,
        "executed_at": "2026-07-27T09:15:33.480Z"
      }
    ]
  }
}
```

<div dir="rtl">

---

## ۲.۶ معاملات OTC

| متد | مسیر | شرح |
|---|---|---|
| `GET` | `/otc-offers` | 🔒 پیشنهادهای دریافتی و ارسالی |
| `GET` | `/otc-offers/{id}` | 🔒 جزئیات |
| `POST` | `/otc-offers` | 🔒 🔑 ایجاد پیشنهاد |
| `POST` | `/otc-offers/{id}/accept` | 🔒 🔑 پذیرش |
| `POST` | `/otc-offers/{id}/counter` | 🔒 🔑 پیشنهاد متقابل |
| `POST` | `/otc-offers/{id}/reject` | 🔒 رد |
| `POST` | `/otc-offers/{id}/cancel` | 🔒 لغو (پیشنهاددهنده) |

</div>

```http
POST /api/v1/otc-offers
Idempotency-Key: ...

{
  "counterparty_organization_id": 291,
  "instrument": "GOLD-995-T0",
  "side": "SELL",
  "quantity_mg": 500000,
  "price_rial": 78500000,
  "settlement_type": "T0",
  "delivery_type": "VAULT_TRANSFER",
  "expires_in_minutes": 30,
  "note": "آماده تحویل فوری"
}
```

<div dir="rtl">

---

## ۲.۷ RFQ

| متد | مسیر | شرح |
|---|---|---|
| `GET` | `/rfqs` | 🔒 درخواست‌های من |
| `GET` | `/rfqs/inbox` | 🔒 درخواست‌های دریافتی |
| `GET` | `/rfqs/{id}` | 🔒 جزئیات |
| `POST` | `/rfqs` | 🔒 🔑 ایجاد درخواست |
| `POST` | `/rfqs/{id}/cancel` | 🔒 لغو |
| `GET` | `/rfqs/{id}/quotes` | 🔒 پیشنهادهای دریافتی |
| `POST` | `/rfqs/{id}/quotes` | 🔒 🔑 ارسال پیشنهاد |
| `POST` | `/rfq-quotes/{id}/accept` | 🔒 🔑 پذیرش پیشنهاد |
| `POST` | `/rfq-quotes/{id}/withdraw` | 🔒 پس گرفتن پیشنهاد |

</div>

```http
POST /api/v1/rfqs
Idempotency-Key: ...

{
  "instrument": "GOLD-995-T0",
  "side": "BUY",
  "quantity_mg": 5000000,
  "min_purity_x10": 9950,
  "settlement_type": "T0",
  "delivery_type": "VAULT_TRANSFER",
  "visibility": "SELECTED",
  "recipient_organization_ids": [12, 45, 88],
  "allow_partial": true,
  "expires_in_minutes": 15
}
```

<div dir="rtl">

---

## ۲.۸ تسویه

| متد | مسیر | شرح |
|---|---|---|
| `GET` | `/settlements` | 🔒 فهرست |
| `GET` | `/settlements/pending` | 🔒 در انتظار اقدام من |
| `GET` | `/settlements/{id}` | 🔒 جزئیات |
| `POST` | `/settlements/{id}/declare-payment` | 🔒 🔑 اعلام پرداخت |
| `POST` | `/settlements/{id}/confirm-payment` | 🔒 🔑 ✍️ تأیید دریافت |
| `POST` | `/settlements/{id}/confirm-delivery` | 🔒 🔑 ✍️ تأیید تحویل طلا |
| `POST` | `/settlements/{id}/cancel` | 🔒 🔑 درخواست لغو |
| `GET` | `/settlements/{id}/events` | 🔒 تاریخچه |

</div>

```http
POST /api/v1/settlements/88231/declare-payment
Idempotency-Key: ...

{
  "payment_reference": "987654321",
  "amount_rial": 19649430000,
  "paid_at": "2026-07-27T10:30:00Z",
  "receipt_document_id": 4421,
  "note": "واریز پایا از حساب ملت"
}
```

<div dir="rtl">

---

## ۲.۹ تهاتر

| متد | مسیر | شرح |
|---|---|---|
| `GET` | `/netting-batches` | 🔒 دسته‌های تهاتر من |
| `GET` | `/netting-batches/{id}` | 🔒 جزئیات و موقعیت من |
| `POST` | `/netting-batches/{id}/accept` | 🔒 🔑 ✍️ پذیرش |
| `POST` | `/netting-batches/{id}/reject` | 🔒 🔑 رد |

---

## ۲.۱۰ طلای فیزیکی و خزانه

| متد | مسیر | شرح |
|---|---|---|
| `GET` | `/lots` | 🔒 lotهای من |
| `GET` | `/lots/{id}` | 🔒 جزئیات |
| `GET` | `/lots/{id}/lineage` | 🔒 شجره‌نامه |
| `GET` | `/lots/{id}/assays` | 🔒 گواهی‌های ری‌گیری |
| `POST` | `/lots/{id}/split` | 🔒 🔑 ✍️ درخواست تفکیک |
| `POST` | `/lots/merge` | 🔒 🔑 ✍️ درخواست ادغام |
| `POST` | `/lots/{id}/send-to-assay` | 🔒 🔑 ارسال برای ری‌گیری |
| `GET` | `/vault/deposits` | 🔒 سپرده‌های من |
| `POST` | `/vault/deposits` | 🔒 🔑 درخواست سپرده‌گذاری |
| `GET` | `/vault/withdrawals` | 🔒 برداشت‌های من |
| `POST` | `/vault/withdrawals` | 🔒 🔑 ✍️ درخواست برداشت |
| `POST` | `/vault/withdrawals/{id}/approve` | 🔒 👑 تأیید دوم |
| `GET` | `/vaults` | 🔒 خزانه‌های در دسترس |
| `GET` | `/verify/{qr_token}` | عمومی — تأیید اصالت با QR |

</div>

```http
GET /api/v1/lots/1287/lineage
```

```json
{
  "data": {
    "lot": { "id": 1287, "lot_code": "GL-00001287" },
    "ancestors": [
      {
        "lot_code": "GL-00000100",
        "operation": "SPLIT",
        "gross_weight_mg": 1000000,
        "purity_x10": 9990,
        "depth": 2,
        "occurred_at": "2026-05-12T08:00:00Z"
      }
    ],
    "descendants": [],
    "operations": [
      {
        "type": "SPLIT",
        "occurred_at": "2026-05-12T08:00:00Z",
        "input_fine_mg": 999000,
        "output_fine_mg": 998900,
        "loss_fine_mg": 100
      }
    ]
  }
}
```

<div dir="rtl">

---

## ۲.۱۱ طرف‌حساب

| متد | مسیر | شرح |
|---|---|---|
| `GET` | `/counterparties` | 🔒 فهرست طرف‌حساب‌ها |
| `GET` | `/counterparties/{orgId}` | 🔒 جزئیات رابطه |
| `GET` | `/counterparties/{orgId}/statement` | 🔒 صورت‌حساب |
| `PUT` | `/counterparties/{orgId}/limits` | 🔒 👑 تعیین سقف |
| `PUT` | `/counterparties/{orgId}/settings` | 🔒 اعتماد/بلاک |
| `POST` | `/counterparties/{orgId}/confirm-balance` | 🔒 درخواست تأیید مانده |
| `GET` | `/members/search` | 🔒 جستجوی اعضا (برای OTC/RFQ) |
| `GET` | `/members/{id}/reputation` | 🔒 اعتبار عمومی عضو |

---

## ۲.۱۲ اختلاف

| متد | مسیر | شرح |
|---|---|---|
| `GET` | `/disputes` | 🔒 پرونده‌های من |
| `GET` | `/disputes/{id}` | 🔒 جزئیات |
| `POST` | `/disputes` | 🔒 🔑 ثبت اختلاف |
| `POST` | `/disputes/{id}/reply` | 🔒 پاسخ |
| `POST` | `/disputes/{id}/accept` | 🔒 🔑 ✍️ پذیرش ادعا |
| `POST` | `/disputes/{id}/evidences` | 🔒 افزودن مدرک |
| `POST` | `/disputes/{id}/messages` | 🔒 پیام در مذاکره |
| `POST` | `/disputes/{id}/propose-settlement` | 🔒 🔑 پیشنهاد تسویه |
| `POST` | `/disputes/{id}/escalate` | 🔒 درخواست میانجی |
| `POST` | `/disputes/{id}/withdraw` | 🔒 پس گرفتن ادعا |

---

## ۲.۱۳ گزارش

| متد | مسیر | شرح |
|---|---|---|
| `GET` | `/reports/gold-flow` | 🔒 گردش طلا |
| `GET` | `/reports/rial-flow` | 🔒 گردش ریال |
| `GET` | `/reports/trades` | 🔒 گزارش معاملات |
| `GET` | `/reports/pnl` | 🔒 سود و زیان |
| `GET` | `/reports/daily-profit` | 🔒 سود روزانه |
| `GET` | `/reports/inventory` | 🔒 موجودی lotها |
| `GET` | `/reports/trial-balance` | 🔒 تراز آزمایشی |
| `GET` | `/reports/fees` | 🔒 کارمزدها |
| `POST` | `/reports/export` | 🔒 درخواست خروجی (ناهمگام) |
| `GET` | `/reports/exports/{id}` | 🔒 وضعیت و لینک دانلود |
| `GET` | `/accounting/journal` | 🔒 دفتر روزنامه |
| `GET` | `/accounting/export` | 🔒 خروجی برای نرم‌افزار حسابداری |

---

## ۲.۱۴ اعلان

| متد | مسیر | شرح |
|---|---|---|
| `GET` | `/notifications` | 🔒 فهرست |
| `GET` | `/notifications/unread-count` | 🔒 تعداد خوانده‌نشده |
| `POST` | `/notifications/{id}/read` | 🔒 علامت‌گذاری |
| `POST` | `/notifications/read-all` | 🔒 همه را خوانده‌شده کن |
| `GET` | `/notifications/preferences` | 🔒 تنظیمات |
| `PUT` | `/notifications/preferences` | 🔒 ویرایش |
| `POST` | `/devices` | 🔒 ثبت دستگاه برای Push |
| `DELETE` | `/devices/{token}` | 🔒 حذف دستگاه |

---

## ۲.۱۵ ریسک

| متد | مسیر | شرح |
|---|---|---|
| `GET` | `/risk/profile` | 🔒 پروفایل ریسک من |
| `GET` | `/risk/limits` | 🔒 سقف‌ها و مصرف |
| `GET` | `/risk/exposure` | 🔒 تعهدات باز |
| `POST` | `/risk/limit-increase-request` | 🔒 👑 درخواست افزایش سقف |
| `GET` | `/risk/collaterals` | 🔒 وثیقه‌های من |

---

## ۲.۱۶ پلتفرم (ادمین)

مسیر جداگانه: `/api/v1/admin/...`

| گروه | نمونه |
|---|---|
| KYC | `GET /admin/kyc/queue`, `POST /admin/kyc/{orgId}/approve` |
| اعضا | `GET /admin/organizations`, `POST /admin/organizations/{id}/suspend` |
| معاملات | `GET /admin/trades`, `GET /admin/orders` |
| تسویه | `GET /admin/settlements/overdue`, `POST /admin/settlements/{id}/reverse` |
| خزانه | `GET /admin/vaults/{id}/inventory`, `POST /admin/vault-operations/{id}/approve` |
| قیمت | `POST /admin/prices/manual`, `PUT /admin/price-sources/{id}` |
| ریسک | `PUT /admin/organizations/{id}/limits`, `GET /admin/risk/dashboard` |
| AML | `GET /admin/aml/flags`, `POST /admin/aml/flags/{id}/resolve` |
| اختلاف | `GET /admin/disputes/queue`, `POST /admin/disputes/{id}/decide` |
| دفتر | `GET /admin/ledger/reconciliation`, `POST /admin/ledger/adjust` |
| تنظیمات | `GET /admin/settings`, `PUT /admin/settings/{key}` |
| Audit | `GET /admin/audit-logs` |

---

## ۲.۱۷ سلامت و متادیتا

| متد | مسیر | شرح |
|---|---|---|
| `GET` | `/health` | عمومی — سلامت سرویس |
| `GET` | `/health/deep` | 🔒 بررسی عمیق (DB, Redis, Queue) |
| `GET` | `/meta/enums` | 🔒 همه مقادیر enum برای کلاینت |
| `GET` | `/meta/settings` | 🔒 تنظیمات عمومی سیستم |
| `GET` | `/meta/calendar` | 🔒 تقویم کاری |
| `GET` | `/openapi.json` | مشخصات OpenAPI |

</div>

```http
GET /api/v1/meta/enums
```

```json
{
  "data": {
    "order_status": [
      { "value": "OPEN", "label": "باز", "label_en": "Open" },
      { "value": "PARTIALLY_FILLED", "label": "اجرای جزئی" },
      { "value": "FILLED", "label": "اجرا شده" }
    ],
    "settlement_status": [ "..." ],
    "dispute_type": [ "..." ]
  },
  "meta": { "version": "2026-07-27" }
}
```

<div dir="rtl">

> این endpoint اجازه می‌دهد کلاینت (به‌ویژه Flutter) بدون به‌روزرسانی
> برنامه، متن نمایشی enumها را دریافت کند. با کش سمت کلاینت و
> `ETag` بهینه می‌شود.

</div>
