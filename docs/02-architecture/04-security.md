<div dir="rtl">

# ۴. امنیت

سیستمی که دارایی با ارزش بالا را ثبت می‌کند، هدف جذابی است. این سند
حداقل‌های الزامی را تعریف می‌کند.

---

## ۴.۱ مدل تهدید (Threat Model)

| # | تهدید | اثر | کنترل |
|---|---|---|---|
| T1 | سرقت اعتبارنامه کاربر | معامله جعلی، انتقال دارایی | 2FA اجباری، تأیید تراکنش، محدودیت IP |
| T2 | تصاحب نشست (Session Hijacking) | همان | توکن کوتاه‌مدت، بایند به دستگاه، ابطال از راه دور |
| T3 | Insider — کارمند اپراتور | دستکاری دفتر | تأیید دوگانه، Audit تغییرناپذیر، تفکیک وظایف |
| T4 | تزریق SQL | افشا/تغییر داده | ORM، Prepared Statement، بازبینی کد |
| T5 | Race Condition روی موجودی | فروش دوباره یک طلا | قفل بدبینانه، CHECK constraint |
| T6 | دستکاری قیمت مرجع | معامله با قیمت غلط | چند منبع، بازه پذیرش، Circuit Breaker |
| T7 | افشای داده KYC | نقض حریم خصوصی | رمزنگاری در سکون، دسترسی حداقلی، Audit خواندن |
| T8 | Replay درخواست مالی | معامله تکراری | Idempotency-Key اجباری |
| T9 | DoS روی Matching | توقف بازار | Rate Limit، صف، auto-scale |
| T10 | دستکاری دفتر توسط DBA | نامشخص ماندن دستکاری | زنجیره hash، بکاپ append-only خارج از دسترس DBA |
| T11 | فیشینگ اعضا | سرقت حساب | آموزش، دامنه رسمی، عدم ارسال لینک در SMS |
| T12 | جعل گواهی ری‌گیری | ورود طلای کم‌عیار | تأیید آزمایشگاه، ری‌گیری تصادفی نمونه‌ای |

---

## ۴.۲ احراز هویت

### جریان ورود

</div>

```
۱) شماره موبایل + رمز عبور
        │
        ▼
۲) بررسی: rate limit (۵ تلاش / ۱۵ دقیقه / IP + phone)
        │
        ▼
۳) عامل دوم (اجباری برای همه نقش‌های دارای دسترسی مالی)
        ├── TOTP  (ترجیحی)
        ├── SMS OTP
        └── بیومتریک دستگاه (موبایل، پس از ثبت اولیه)
        │
        ▼
۴) صدور توکن
        ├── Access Token   : 15 دقیقه
        └── Refresh Token  : 30 روز، بایند به device_id
        │
        ▼
۵) ثبت نشست: IP, User-Agent, device_id, geo
        │
        ▼
۶) اعلان «ورود جدید» در صورت دستگاه ناشناس
```

<div dir="rtl">

### قواعد رمز عبور

| قاعده | مقدار |
|---|---|
| حداقل طول | ۱۲ کاراکتر |
| بررسی رمزهای فاش‌شده | HaveIBeenPwned API (k-anonymity) |
| الگوریتم hash | `bcrypt` با cost=12 یا `argon2id` |
| انقضای اجباری | ندارد (طبق راهنمای NIST) |
| قفل حساب | پس از ۱۰ تلاش ناموفق، ۳۰ دقیقه |

### تأیید تراکنش (Transaction Signing)

عملیات با ارزش بالا نیازمند تأیید مجدد است، حتی اگر کاربر لاگین باشد:

</div>

```
معامله > 1,000,000,000 ریال    ► نیاز به TOTP / بیومتریک مجدد
خروج طلا از خزانه              ► نیاز به TOTP + تأیید دوم کاربر دیگر
افزودن حساب بانکی               ► نیاز به TOTP + تأیید SMS
تغییر رمز / 2FA                 ► نیاز به رمز فعلی + TOTP
```

<div dir="rtl">

---

## ۴.۳ مجوزدهی (Authorization)

سه لایه بررسی، **هر سه اجباری**:

</div>

```
لایه ۱ — Authentication:  کاربر کیست؟
لایه ۲ — Tenancy:         آیا این منبع متعلق به سازمان اوست؟
لایه ۳ — Permission:      آیا نقش او این عملیات را مجاز می‌کند؟
```

<div dir="rtl">

### Global Scope برای Tenancy

</div>

```php
// هر مدل متعلق به سازمان
abstract class TenantModel extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $q) {
            if ($orgId = auth()->user()?->organization_id) {
                $q->where('organization_id', $orgId);
            }
        });
    }
}
```

<div dir="rtl">

> ⚠️ Global Scope کافی نیست. برای هر عملیات نوشتن، **بررسی صریح** هم لازم است،
> چون `withoutGlobalScope` یا کوئری خام می‌تواند آن را دور بزند.

</div>

```php
// در Policy
public function cancel(User $user, Order $order): bool
{
    return $order->organization_id === $user->organization_id   // صریح
        && $user->hasPermission(Permission::ORDER_CANCEL_OWN)
        && ($order->created_by_user_id === $user->id
            || $user->hasPermission(Permission::ORDER_CANCEL_ANY));
}
```

<div dir="rtl">

---

## ۴.۴ رمزنگاری

### داده در حال انتقال
- TLS 1.3 اجباری، HSTS با `max-age=31536000; includeSubDomains; preload`
- Certificate Pinning در اپ موبایل
- بدون پشتیبانی از TLS < 1.2

### داده در حالت سکون

| داده | روش |
|---|---|
| رمز عبور | bcrypt/argon2id (یک‌طرفه) |
| کد ملی / شناسه ملی | AES-256-GCM + ذخیره `SHA-256` جداگانه برای جستجو |
| شماره حساب / IBAN | AES-256-GCM |
| اسناد KYC | رمزنگاری سمت سرور در S3 (SSE-KMS) + کلید اختصاصی |
| توکن API | ذخیره hash، نمایش plaintext فقط یک بار |
| کل دیتابیس | رمزنگاری سطح دیسک (LUKS / EBS encryption) |
| بکاپ | رمزنگاری مستقل با کلید جداگانه |

</div>

```php
// Laravel encrypted cast
protected $casts = [
    'national_id' => 'encrypted',
    'iban'        => 'encrypted',
];

// برای جستجو: ستون blind index
// national_id_hash = hash_hmac('sha256', $nationalId, config('app.blind_index_key'))
```

<div dir="rtl">

### مدیریت کلید

</div>

```
فاز ۱: کلیدها در متغیر محیطی + مدیریت با Vault/SSM
فاز ۳: HSM برای کلیدهای امضای دفتر  ◄── الزامی برای عملیات واقعی

چرخش کلید (Key Rotation):
  ├── کلید داده     : سالانه
  ├── کلید توکن API : در صورت نشت
  └── کلید بکاپ     : سالانه
```

<div dir="rtl">

---

## ۴.۵ Idempotency

هر عملیات **تغییردهنده وضعیت مالی** باید idempotent باشد.

</div>

```http
POST /api/v1/orders
Idempotency-Key: 0f9c2b3a-4d5e-6f7a-8b9c-0d1e2f3a4b5c
Content-Type: application/json
```

<div dir="rtl">

</div>

```sql
CREATE TABLE idempotency_keys (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key`           CHAR(36)        NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  endpoint        VARCHAR(191)    NOT NULL,
  request_hash    CHAR(64)        NOT NULL,
  status          ENUM('processing','completed','failed') NOT NULL,
  response_code   SMALLINT UNSIGNED NULL,
  response_body   JSON            NULL,
  locked_at       TIMESTAMP       NULL,
  created_at      TIMESTAMP       NOT NULL,
  expires_at      TIMESTAMP       NOT NULL,
  UNIQUE KEY uq_key_org (`key`, organization_id),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB;
```

<div dir="rtl">

**رفتار:**

| حالت | پاسخ |
|---|---|
| کلید جدید | اجرا، ذخیره نتیجه |
| کلید تکراری + همان بدنه + وضعیت `completed` | پاسخ ذخیره‌شده، `200` |
| کلید تکراری + همان بدنه + وضعیت `processing` | `409 Conflict` — «در حال پردازش» |
| کلید تکراری + **بدنه متفاوت** | `422` — «کلید تکراری با محتوای متفاوت» |
| کلید منقضی (> ۲۴ ساعت) | مثل جدید |

---

## ۴.۶ محدودیت نرخ (Rate Limiting)

</div>

```php
RateLimiter::for('auth', fn ($r) =>
    Limit::perMinutes(15, 5)->by($r->input('phone').'|'.$r->ip()));

RateLimiter::for('orders', fn ($r) =>
    Limit::perMinute(60)->by($r->user()->organization_id));

RateLimiter::for('market-data', fn ($r) =>
    Limit::perMinute(300)->by($r->user()->organization_id));

RateLimiter::for('reports', fn ($r) =>
    Limit::perHour(20)->by($r->user()->organization_id));

RateLimiter::for('kyc-upload', fn ($r) =>
    Limit::perHour(50)->by($r->user()->organization_id));
```

<div dir="rtl">

علاوه بر این، محدودیت سطح WAF/nginx برای IP و محدودیت سراسری برای
جلوگیری از DoS.

---

## ۴.۷ Audit Log

### چه چیزهایی ثبت می‌شود

</div>

```
همه‌چیز در این دسته‌ها:
  ├── ورود / خروج / تغییر رمز / تغییر 2FA
  ├── هر تغییر در KYC و اسناد
  ├── هر ثبت/لغو سفارش
  ├── هر معامله
  ├── هر ورودی دفتر کل
  ├── هر عملیات تسویه
  ├── هر عملیات خزانه
  ├── هر تغییر نقش یا مجوز
  ├── هر تغییر سقف یا پروفایل ریسک
  ├── هر اقدام کارکنان پلتفرم
  └── هر خواندن داده حساس (اسناد KYC، صورت‌حساب دیگران)
```

<div dir="rtl">

### ساختار

</div>

```sql
CREATE TABLE audit_logs (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  occurred_at      TIMESTAMP(6)    NOT NULL,
  actor_type       ENUM('user','system','platform_staff','api_client') NOT NULL,
  actor_id         BIGINT UNSIGNED NULL,
  organization_id  BIGINT UNSIGNED NULL,
  action           VARCHAR(100)    NOT NULL,   -- 'order.create'
  subject_type     VARCHAR(100)    NULL,       -- 'Order'
  subject_id       BIGINT UNSIGNED NULL,
  before_state     JSON            NULL,
  after_state      JSON            NULL,
  ip_address       VARBINARY(16)   NULL,
  user_agent       VARCHAR(500)    NULL,
  session_id       CHAR(36)        NULL,
  request_id       CHAR(36)        NULL,
  result           ENUM('success','failure','denied') NOT NULL,
  failure_reason   VARCHAR(500)    NULL,
  prev_hash        CHAR(64)        NULL,       -- زنجیره
  row_hash         CHAR(64)        NOT NULL,
  KEY idx_org_time (organization_id, occurred_at),
  KEY idx_actor_time (actor_id, occurred_at),
  KEY idx_subject (subject_type, subject_id),
  KEY idx_action_time (action, occurred_at)
) ENGINE=InnoDB;
```

<div dir="rtl">

### زنجیره hash

</div>

```
row_hash = SHA256(
    prev_hash || occurred_at || actor_id || action ||
    subject_type || subject_id || after_state
)
```

<div dir="rtl">

هر شب یک job زنجیره را از ابتدا بازبینی می‌کند. شکست زنجیره = هشدار بحرانی.

### قواعد سخت

</div>

```sql
-- کاربر اپلیکیشن حق UPDATE و DELETE روی audit_logs ندارد
REVOKE UPDATE, DELETE ON goldb2b.audit_logs FROM 'app_user'@'%';
GRANT  INSERT, SELECT ON goldb2b.audit_logs TO 'app_user'@'%';

-- همین قاعده برای ledger_entries
REVOKE UPDATE, DELETE ON goldb2b.ledger_entries FROM 'app_user'@'%';
GRANT  INSERT, SELECT ON goldb2b.ledger_entries TO 'app_user'@'%';
```

<div dir="rtl">

این تنها روش قابل اتکا برای تضمین append-only بودن در سطح دیتابیس است.

### نگهداری

| نوع | مدت |
|---|---|
| رویدادهای مالی | ۱۰ سال |
| رویدادهای دسترسی | ۳ سال |
| رویدادهای فنی | ۱ سال |

---

## ۴.۸ امنیت API

| کنترل | جزئیات |
|---|---|
| احراز هویت | Laravel Sanctum (Bearer Token) |
| کلید API برای یکپارچگی | جفت کلید/راز، امضای HMAC روی بدنه |
| Scope | هر کلید فقط دسترسی‌های صریح اعلام‌شده |
| محدودیت IP | لیست سفید اختیاری برای هر کلید |
| Timestamp | درخواست با انحراف بیش از ۵ دقیقه رد می‌شود |
| CORS | فقط دامنه‌های اعلام‌شده |
| Headers | `X-Content-Type-Options`, `X-Frame-Options: DENY`, `CSP` |
| اندازه بدنه | حداکثر ۱MB (به‌جز آپلود) |

### امضای Webhook خروجی

</div>

```
X-GoldB2B-Signature: t=1735689600,v1=5257a869e7...
X-GoldB2B-Event: trade.executed

signature = HMAC_SHA256(secret, "{timestamp}.{raw_body}")
```

<div dir="rtl">

گیرنده باید timestamp را بررسی کند (پنجره ۵ دقیقه) و امضا را با
مقایسه زمان‌ثابت تطبیق دهد.

---

## ۴.۹ امنیت موبایل

| کنترل | جزئیات |
|---|---|
| ذخیره توکن | Keychain (iOS) / EncryptedSharedPreferences (Android) |
| Certificate Pinning | پین کردن گواهی سرور |
| تشخیص Root/Jailbreak | هشدار + محدود کردن عملیات پرارزش |
| قفل صفحه | بازگشت به لاگین پس از ۵ دقیقه بی‌کاری |
| اسکرین‌شات | مسدود در صفحات مالی (`FLAG_SECURE`) |
| Deep Link | اعتبارسنجی کامل، بدون اجرای عملیات مستقیم از لینک |
| Debug | غیرفعال بودن logging در build تولیدی |

---

## ۴.۱۰ امنیت عملیاتی

### تفکیک وظایف

</div>

```
هیچ‌کس نباید همزمان:
  ✗ دسترسی مستقیم به دیتابیس تولید  +  دسترسی به کد
  ✗ توانایی اصلاح دفتر  +  توانایی تأیید اصلاح
  ✗ دسترسی به بکاپ  +  دسترسی به کلید رمزنگاری بکاپ
```

<div dir="rtl">

### دسترسی به محیط تولید

| قاعده | جزئیات |
|---|---|
| دسترسی مستقیم DB | ممنوع؛ فقط از طریق ابزار با Audit |
| دسترسی SSH | فقط با bastion + MFA + ضبط جلسه |
| تغییر تنظیمات | فقط از طریق IaC و PR |
| داده تولید در محیط توسعه | ممنوع؛ فقط داده مصنوعی |

### واکنش به رخداد

</div>

```
تشخیص ► مهار ► ریشه‌یابی ► ترمیم ► بازیابی ► درس‌آموخته

تیم واکنش:  On-call مهندس + مسئول امنیت + مسئول انطباق
SLA اعلان:  رخداد بحرانی ► ۱۵ دقیقه
            نشت داده     ► اعلان به مراجع طبق الزام قانونی
```

<div dir="rtl">

---

## ۴.۱۱ چک‌لیست پیش از استقرار تولید

</div>

```
[ ] APP_DEBUG=false
[ ] APP_ENV=production
[ ] کلیدهای تولیدی متفاوت از staging
[ ] HTTPS اجباری + HSTS
[ ] هدرهای امنیتی فعال
[ ] Rate limit روی همه endpointها
[ ] Idempotency روی همه عملیات مالی
[ ] REVOKE UPDATE/DELETE روی ledger_entries و audit_logs
[ ] بکاپ خودکار + تست بازیابی انجام شده
[ ] مانیتورینگ و هشدار فعال
[ ] اسکن وابستگی‌ها (composer audit / dart pub outdated)
[ ] اسکن رازها در مخزن (gitleaks)
[ ] تست نفوذ انجام شده
[ ] فرآیند واکنش به رخداد مکتوب و تمرین‌شده
[ ] 2FA اجباری برای همه کارکنان پلتفرم
```

</div>
