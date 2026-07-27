<div dir="rtl">

# ۱. هویت، عضویت و KYC

## ۱.۱ مدل دامنه

</div>

```
Organization  (عضو صنفی — مالک دارایی)
│
├── type: INDIVIDUAL | LEGAL_ENTITY
├── status: PENDING → UNDER_REVIEW → VERIFIED → ACTIVE → …
│
├── Identity
│   ├── (حقیقی)  نام، نام خانوادگی، کد ملی، تاریخ تولد، نام پدر
│   └── (حقوقی)  نام شرکت، شناسه ملی، شماره ثبت، تاریخ ثبت
│
├── BusinessLicense  (جواز کسب)
│   ├── شماره جواز
│   ├── اتحادیه صادرکننده
│   ├── نوع فعالیت
│   ├── تاریخ صدور / انقضا
│   ├── آدرس واحد صنفی
│   └── وضعیت اعتبار
│
├── TaxProfile
│   ├── کد اقتصادی
│   ├── کد رهگیری مالیاتی
│   └── وضعیت ثبت‌نام
│
├── BankAccount[]  (چند حساب)
│   ├── IBAN
│   ├── نام بانک
│   ├── نام صاحب حساب
│   ├── is_primary
│   └── وضعیت تأیید
│
├── Representative[]  (نمایندگان مجاز)
│   ├── نام، کد ملی
│   ├── نوع اختیار (معامله / تحویل / امضا)
│   ├── سقف اختیار
│   └── تاریخ اعتبار
│
├── Signatory[]  (صاحبان امضا — برای حقوقی)
│
├── Document[]
│   ├── نوع سند
│   ├── فایل رمزنگاری‌شده
│   ├── hash
│   └── وضعیت تأیید
│
├── Address
├── ContactInfo
├── RiskProfile      ► ماژول Risk
├── LedgerAccounts   ► ماژول Ledger
└── VerificationHistory[]
```

<div dir="rtl">

---

## ۱.۲ ماشین حالت عضو

</div>

```
                    ┌─────────────────────────────────────────────┐
                    │                                             │
   ┌─────────┐      │  ┌──────────────┐                           │
   │ PENDING │──────┼─►│ UNDER_REVIEW │                           │
   └─────────┘      │  └──────┬───────┘                           │
   ثبت اولیه         │         │                                   │
                    │         ├──────────► ┌───────────────┐      │
                    │         │            │ INFO_REQUIRED │──────┘
                    │         │            └───────────────┘
                    │         │            نقص مدارک، قابل تکمیل
                    │         │
                    │         ├──────────► ┌──────────┐
                    │         │            │ REJECTED │  (نهایی)
                    │         │            └──────────┘
                    │         │
                    │         ▼
                    │  ┌──────────┐      ┌────────┐
                    └─►│ VERIFIED │─────►│ ACTIVE │◄──────┐
                       └──────────┘      └───┬────┘       │
                       تأیید KYC             │            │
                       دفتر ساخته شد          │            │
                                             │            │
                        ┌────────────────────┼────────────┘
                        │                    │
                        ▼                    ▼
                 ┌────────────┐       ┌───────────┐
                 │ RESTRICTED │       │ SUSPENDED │
                 └─────┬──────┘       └─────┬─────┘
                       │                    │
                 محدودیت جزئی           تعلیق کامل
                 (فقط فروش /            (بدون معامله)
                  سقف پایین)                 │
                                             ▼
                                       ┌──────────┐
                                       │  CLOSED  │  (نهایی)
                                       └──────────┘
```

<div dir="rtl">

### قواعد گذار

| از | به | شرط | اثر جانبی |
|---|---|---|---|
| `PENDING` | `UNDER_REVIEW` | ارسال کامل مدارک | اعلان به Compliance |
| `UNDER_REVIEW` | `INFO_REQUIRED` | نقص مدرک | اعلان با ذکر دقیق نقص |
| `UNDER_REVIEW` | `VERIFIED` | تأیید افسر انطباق | — |
| `VERIFIED` | `ACTIVE` | ایجاد حساب دفتر + تخصیص سقف | خودکار |
| `ACTIVE` | `RESTRICTED` | نقض سقف / پرچم AML / انقضای جواز | لغو سفارش‌های باز |
| `ACTIVE` | `SUSPENDED` | تخلف جدی / تصمیم انطباق | لغو همه سفارش‌ها |
| `SUSPENDED` | `ACTIVE` | رفع مشکل + تأیید دوگانه | — |
| هر حالت | `CLOSED` | درخواست عضو یا تصمیم نهایی | **فقط اگر مانده صفر و تعهد باز نباشد** |

### قاعده سخت برای `CLOSED`

</div>

```
شرایط لازم برای بستن حساب:
  ✓ مانده طلا = 0
  ✓ مانده ریال = 0
  ✓ مانده رزروشده = 0
  ✓ هیچ سفارش باز
  ✓ هیچ تسویه در جریان
  ✓ هیچ اختلاف باز
  ✓ هیچ lot تحت مالکیت در خزانه

در غیر این صورت ► CLOSING (وضعیت انتقالی، فقط تسویه مجاز)
```

<div dir="rtl">

---

## ۱.۳ اسناد مورد نیاز

### عضو حقیقی

| سند | اجباری | اعتبارسنجی |
|---|---|---|
| تصویر کارت ملی (رو و پشت) | ✅ | خوانایی، تطابق کد ملی، الگوریتم کنترل کد ملی |
| تصویر جواز کسب | ✅ | تاریخ اعتبار، تطابق نام |
| سلفی با کارت ملی | ✅ | تطابق چهره (دستی در فاز ۱) |
| گواهی عضویت اتحادیه | ⬜ | — |
| تصویر آخرین صفحه شناسنامه | ⬜ | — |
| اجاره‌نامه/سند محل کسب | ⬜ | تطابق آدرس با جواز |

### عضو حقوقی

| سند | اجباری |
|---|---|
| روزنامه رسمی تأسیس | ✅ |
| آخرین روزنامه رسمی تغییرات | ✅ |
| اساسنامه | ✅ |
| جواز کسب شرکت | ✅ |
| کارت ملی مدیرعامل و اعضای هیئت‌مدیره | ✅ |
| گواهی امضا | ✅ |
| کد اقتصادی | ✅ |
| معرفی‌نامه نمایندگان مجاز | ⬜ |

### قواعد ذخیره‌سازی سند

</div>

```
۱. فایل هرگز در دیتابیس ذخیره نمی‌شود ► S3 با SSE-KMS
۲. مسیر فایل تصادفی و غیرقابل حدس (UUID)
۳. دسترسی فقط با pre-signed URL کوتاه‌مدت (۵ دقیقه)
۴. هر دانلود در Audit Log ثبت می‌شود
۵. SHA-256 فایل در دیتابیس برای تشخیص دستکاری
۶. حداکثر ۱۰MB، فقط JPG/PNG/PDF
۷. حذف EXIF از تصاویر
۸. اسکن بدافزار پیش از پذیرش
```

<div dir="rtl">

---

## ۱.۴ اعتبارسنجی داده

### کد ملی (۱۰ رقمی)

</div>

```php
function isValidNationalId(string $id): bool
{
    if (!preg_match('/^\d{10}$/', $id)) return false;
    if (preg_match('/^(\d)\1{9}$/', $id)) return false;   // همه ارقام یکسان

    $check = (int) $id[9];
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += ((int) $id[$i]) * (10 - $i);
    }
    $rem = $sum % 11;

    return $rem < 2 ? $check === $rem : $check === 11 - $rem;
}
```

<div dir="rtl">

### شناسه ملی اشخاص حقوقی (۱۱ رقمی)

</div>

```php
function isValidLegalId(string $id): bool
{
    if (!preg_match('/^\d{11}$/', $id)) return false;

    $decimal = (int) $id[9];
    if ($decimal === 5) return false;

    $prefix = (int) substr($id, 0, 10);
    if ($prefix === 0) return false;

    $coefficients = [29, 27, 23, 19, 17, 29, 27, 23, 19, 17];
    $base = $decimal + 2;
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $sum += (((int) $id[$i]) + $base) * $coefficients[$i];
    }
    $rem = $sum % 11;
    $rem = $rem === 10 ? 0 : $rem;

    return $rem === (int) $id[10];
}
```

<div dir="rtl">

### شبا (IBAN ایران — ۲۶ کاراکتر)

</div>

```php
function isValidIranIban(string $iban): bool
{
    $iban = strtoupper(str_replace(' ', '', $iban));
    if (!preg_match('/^IR\d{24}$/', $iban)) return false;

    // انتقال ۴ کاراکتر اول به انتها و تبدیل حروف
    $rearranged = substr($iban, 4) . '1827' . substr($iban, 2, 2);
    //                                 I=18, R=27

    return bcmod($rearranged, '97') === '1';
}
```

<div dir="rtl">

### شماره موبایل ایران

</div>

```php
// نرمال‌سازی به فرمت واحد: 989123456789
function normalizeMobile(string $m): ?string
{
    $m = preg_replace('/\D/', '', $m);
    $m = preg_replace('/^(0098|98|0)/', '', $m);
    return preg_match('/^9\d{9}$/', $m) ? '98' . $m : null;
}
```

<div dir="rtl">

---

## ۱.۵ جریان بررسی KYC (سمت Compliance Officer)

</div>

```
┌─────────────────────────────────────────────────────────┐
│  صف بررسی                                                │
│  ┌───────────────────────────────────────────────────┐  │
│  │ ORG-1042  طلافروشی کریمی   حقیقی   ۲ روز در صف     │  │
│  │ ORG-1043  زرگری پارس       حقوقی   ۴ ساعت          │  │
│  │ ORG-1044  آبشده اصفهان     حقیقی   ۱۲ ساعت         │  │
│  └───────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────┐
│  صفحه بررسی — ORG-1042                                   │
│                                                         │
│  ┌─── اطلاعات ──────────┐  ┌─── اسناد ───────────────┐  │
│  │ نام: علی کریمی        │  │ [کارت ملی]   ✅ تأیید   │  │
│  │ کد ملی: 00•••••••1   │  │ [جواز کسب]   ⏳ بررسی   │  │
│  │ جواز: 12345          │  │ [سلفی]       ✅ تأیید   │  │
│  │ انقضا: 1405/06/31    │  │                        │  │
│  │ اتحادیه: طلا و جواهر │  │                        │  │
│  │ شهر: تهران            │  │                        │  │
│  └──────────────────────┘  └────────────────────────┘  │
│                                                         │
│  ┌─── بررسی‌های خودکار ────────────────────────────────┐ │
│  │ ✅ کد ملی معتبر                                     │ │
│  │ ✅ IBAN معتبر و نام صاحب حساب مطابق                  │ │
│  │ ⚠️ جواز کسب ۸ ماه تا انقضا                          │ │
│  │ ✅ عدم تطابق با فهرست محدودشده                       │ │
│  │ ✅ عدم ثبت‌نام تکراری با همین کد ملی                 │ │
│  └────────────────────────────────────────────────────┘ │
│                                                         │
│  [تأیید]  [درخواست اطلاعات]  [رد]                        │
│  یادداشت (اجباری): ______________________               │
└─────────────────────────────────────────────────────────┘
```

<div dir="rtl">

**قواعد:**
- هر تصمیم نیازمند یادداشت مکتوب
- افسر انطباق نمی‌تواند سازمان خودش را بررسی کند
- بازبینی تصمیم فقط توسط `PLATFORM_ADMIN`
- تمام مشاهدات اسناد در Audit ثبت می‌شود

---

## ۱.۶ بازبینی دوره‌ای

</div>

```
سطح ریسک عضو ──► دوره بازبینی
    LOW      ──► هر ۳۶ ماه
    MEDIUM   ──► هر ۲۴ ماه
    HIGH     ──► هر ۱۲ ماه

رویدادهای محرک بازبینی فوری:
  ├── انقضای جواز کسب
  ├── تغییر مالکیت / مدیرعامل
  ├── تغییر آدرس واحد صنفی
  ├── پرچم AML با شدت بالا
  ├── افزایش ناگهانی حجم (> ۵ برابر میانگین)
  └── درخواست افزایش سقف
```

<div dir="rtl">

### یادآوری انقضای جواز

</div>

```
۳۰ روز مانده  ► اعلان درون‌برنامه‌ای + ایمیل
۱۰ روز مانده  ► اعلان + پیامک
 ۱ روز مانده  ► اعلان + پیامک + هشدار در داشبورد
منقضی شد      ► وضعیت ► RESTRICTED
                 - سفارش‌های باز لغو می‌شود
                 - فقط تسویه تعهدات موجود مجاز است
                 - بدون امکان معامله جدید
```

<div dir="rtl">

---

## ۱.۷ نمایندگان مجاز

مسئله واقعی بازار: صاحب مغازه معامله نمی‌کند، کارمندش می‌کند.

</div>

```
Organization: طلافروشی کریمی
│
├── User: علی کریمی      نقش: OWNER
│
├── User: حسن رضایی      نقش: TRADER
│   └── Representative Record:
│       ├── کد ملی: ...
│       ├── نوع اختیار: معامله
│       ├── سقف: 2 kg در روز
│       ├── اعتبار تا: 1405/12/29
│       └── سند معرفی‌نامه: [فایل]
│
└── User: زهرا احمدی     نقش: ACCOUNTANT
    └── بدون اختیار معامله
```

<div dir="rtl">

**قواعد:**
- کاربر با نقش `TRADER` بدون `Representative` معتبر نمی‌تواند معامله کند
- انقضای اختیار نماینده = تعلیق خودکار دسترسی معاملاتی او
- هر معامله، `representative_id` را نیز ثبت می‌کند (برای مسئولیت‌پذیری)

---

## ۱.۸ مدل داده (خلاصه)

</div>

```sql
organizations
  id, type, status, display_name, national_id_enc, national_id_hash,
  legal_id_enc, legal_id_hash, registration_no, established_at,
  union_name, city, market_name, address, postal_code,
  phone, email, website, activated_at, suspended_at, closed_at,
  risk_level, created_at, updated_at

business_licenses
  id, organization_id, license_no, issuing_union, activity_type,
  issued_at, expires_at, status, document_id, verified_at, verified_by

bank_accounts
  id, organization_id, iban_enc, iban_hash, bank_name, account_holder_name,
  is_primary, status, verified_at, verified_by

representatives
  id, organization_id, user_id, full_name, national_id_enc,
  authority_type, daily_limit_mg, valid_from, valid_until,
  document_id, status

documents
  id, organization_id, type, storage_path, file_hash, mime_type,
  size_bytes, status, uploaded_by, verified_at, verified_by,
  rejection_reason

kyc_reviews
  id, organization_id, reviewer_user_id, decision, notes,
  automated_checks JSON, reviewed_at

users
  id, organization_id, phone, email, password_hash, full_name,
  status, two_factor_secret_enc, two_factor_confirmed_at,
  last_login_at, last_login_ip

roles / permissions / role_user / permission_role
```

<div dir="rtl">

جزئیات کامل در [`04-data/02-schema-mysql.md`](../04-data/02-schema-mysql.md).

</div>
