<div dir="rtl">

# فهرست مستندات Gold B2B

> ← بازگشت به [README اصلی](../README.md)

---

## چگونه این مستندات را بخوانم؟

بسته به نقش شما، مسیر پیشنهادی متفاوت است:

### 👔 مدیر محصول / ذی‌نفع کسب‌وکار

</div>

```
۱. 00-overview/01-vision.md          چرا این محصول؟
۲. 00-overview/03-market-context.md  بازار امروز چطور کار می‌کند؟
۳. 00-overview/04-scope.md           چه چیزی می‌سازیم، چه چیزی نه؟
۴. 01-product/03-feature-map.md      فهرست قابلیت‌ها
۵. 01-product/04-roadmap.md          چه زمانی؟
۶. 10-compliance/01-regulatory.md    ⚠️ مسدودکننده‌های حقوقی
```

<div dir="rtl">

### 🏗️ معمار / توسعه‌دهنده ارشد

</div>

```
۱. 00-overview/02-glossary.md              زبان مشترک
۲. 02-architecture/05-adr.md               چرا این تصمیمات؟
۳. 02-architecture/01-system-architecture.md
۴. 02-architecture/02-modules.md           مرزها
۵. 03-domain/03-ledger.md                  ⭐ مهم‌ترین سند
۶. 04-data/01-erd.md
۷. 11-appendix/03-worked-examples.md       همه‌چیز در عمل
```

<div dir="rtl">

### 💻 توسعه‌دهنده بک‌اند

</div>

```
۱. 00-overview/02-glossary.md
۲. 06-backend-laravel/01-project-structure.md
۳. 06-backend-laravel/02-implementation-guide.md
۴. 03-domain/08-calculation-engine.md      ⭐ فرمول‌ها
۵. 11-appendix/01-formulas.md              ⭐ مرجع محاسباتی
۶. 04-data/02-schema-mysql.md
۷. 11-appendix/02-state-machines.md
۸. سند دامنه مربوط به ماژول خودتان
```

<div dir="rtl">

### 📱 توسعه‌دهنده موبایل

</div>

```
۱. 00-overview/02-glossary.md
۲. 05-api/01-conventions.md
۳. 05-api/02-endpoints.md
۴. 07-mobile-flutter/01-architecture.md
۵. 07-mobile-flutter/02-screens.md
۶. 01-product/02-user-journeys.md
```

<div dir="rtl">

### 🎨 طراح UI/UX

</div>

```
۱. 01-product/01-personas-roles.md
۲. 01-product/02-user-journeys.md
۳. 07-mobile-flutter/02-screens.md
۴. 08-frontend-web/01-web-panels.md
۵. 00-overview/03-market-context.md   ⭐ درک کاربر واقعی
```

<div dir="rtl">

### 🛡️ مسئول انطباق / حقوقی

</div>

```
۱. 10-compliance/01-regulatory.md     ⭐ شروع از اینجا
۲. 00-overview/04-scope.md            فرضیات
۳. 03-domain/01-identity-kyc.md
۴. 03-domain/12-aml-compliance.md
۵. 03-domain/13-dispute.md
۶. 02-architecture/04-security.md
```

<div dir="rtl">

### ⚙️ DevOps / SRE

</div>

```
۱. 02-architecture/01-system-architecture.md
۲. 09-operations/01-deployment.md
۳. 02-architecture/03-events.md       صف‌ها
۴. 02-architecture/04-security.md
۵. 04-data/02-schema-mysql.md         تنظیمات دیتابیس
```

<div dir="rtl">

---

## فهرست کامل

### ۰۰ — نمای کلی

| سند | موضوع |
|---|---|
| [`01-vision.md`](00-overview/01-vision.md) | مسئله، ارزش پیشنهادی، معیارهای موفقیت |
| [`02-glossary.md`](00-overview/02-glossary.md) | واژه‌نامه و **قرارداد واحدها** |
| [`03-market-context.md`](00-overview/03-market-context.md) | جریان امروزی بازار آب‌شده |
| [`04-scope.md`](00-overview/04-scope.md) | دامنه، فرضیات، محدودیت‌ها، ریسک‌ها |

### ۰۱ — محصول

| سند | موضوع |
|---|---|
| [`01-personas-roles.md`](01-product/01-personas-roles.md) | پرسوناها، نقش‌ها، ماتریس RBAC، تأیید دوگانه |
| [`02-user-journeys.md`](01-product/02-user-journeys.md) | ۸ مسیر کاربری کامل |
| [`03-feature-map.md`](01-product/03-feature-map.md) | ۲۰ ماژول، ~۱۵۰ قابلیت با اولویت |
| [`04-roadmap.md`](01-product/04-roadmap.md) | فاز ۰ تا ۳ |

### ۰۲ — معماری

| سند | موضوع |
|---|---|
| [`01-system-architecture.md`](02-architecture/01-system-architecture.md) | معماری کلان، همزمانی، Matching |
| [`02-modules.md`](02-architecture/02-modules.md) | مرزبندی، Contract، رویدادها |
| [`03-events.md`](02-architecture/03-events.md) | Event Bus، صف‌ها، Job زمان‌بندی‌شده |
| [`04-security.md`](02-architecture/04-security.md) | مدل تهدید، رمزنگاری، Audit، Idempotency |
| [`05-adr.md`](02-architecture/05-adr.md) | ۱۵ تصمیم معماری با دلیل |

### ۰۳ — دامنه (۱۵ سند)

| سند | موضوع |
|---|---|
| [`01-identity-kyc.md`](03-domain/01-identity-kyc.md) | عضویت، KYC، اعتبارسنجی کد ملی/شبا |
| [`02-gold-lot-assay.md`](03-domain/02-gold-lot-assay.md) | GoldLot، ری‌گیری، Split/Merge/Melt، شجره‌نامه |
| [`03-ledger.md`](03-domain/03-ledger.md) | ⭐ **دفتر کل — مهم‌ترین سند** |
| [`04-trading.md`](03-domain/04-trading.md) | سفارش، Matching، OTC، RFQ، جلسه بازار |
| [`05-settlement.md`](03-domain/05-settlement.md) | تسویه، الگوها، تهاتر، Overdue |
| [`06-custody-vault.md`](03-domain/06-custody-vault.md) | خزانه، ورود/خروج، انبارگردانی |
| [`07-pricing.md`](03-domain/07-pricing.md) | منابع قیمت، ارزش ذاتی، حباب، Circuit Breaker |
| [`08-calculation-engine.md`](03-domain/08-calculation-engine.md) | ⭐ Value Object، گِردکردن، سود و زیان |
| [`09-accounting.md`](03-domain/09-accounting.md) | چارت حساب، ثبت دوطرفه، گزارش‌ها |
| [`10-counterparty.md`](03-domain/10-counterparty.md) | طرف‌حساب، صورت‌حساب، تمرکز ریسک |
| [`11-risk-credit.md`](03-domain/11-risk-credit.md) | سقف، امتیاز اعتباری، وثیقه، آزمون تنش |
| [`12-aml-compliance.md`](03-domain/12-aml-compliance.md) | موتور قواعد، ۲۵+ قاعده، تشخیص چرخه |
| [`13-dispute.md`](03-domain/13-dispute.md) | انواع اختلاف، فرآیند، ری‌گیری ثالث |
| [`14-reputation.md`](03-domain/14-reputation.md) | سنجه‌ها، سطوح تأیید، ضدبازی |
| [`15-notification-reporting.md`](03-domain/15-notification-reporting.md) | ۵۰+ اعلان، گزارش‌ها |

### ۰۴ — داده

| سند | موضوع |
|---|---|
| [`01-erd.md`](04-data/01-erd.md) | ~۸۲ جدول، روابط، ایندکس، پارتیشن |
| [`02-schema-mysql.md`](04-data/02-schema-mysql.md) | DDL کامل، seed، تنظیمات MySQL |

### ۰۵ — API

| سند | موضوع |
|---|---|
| [`01-conventions.md`](05-api/01-conventions.md) | قالب پاسخ، خطاها، صفحه‌بندی، Idempotency |
| [`02-endpoints.md`](05-api/02-endpoints.md) | ~۱۵۰ endpoint |
| [`03-realtime-webhooks.md`](05-api/03-realtime-webhooks.md) | WebSocket و Webhook |

### ۰۶ — بک‌اند

| سند | موضوع |
|---|---|
| [`01-project-structure.md`](06-backend-laravel/01-project-structure.md) | ساختار Laravel، ماژول، CI |
| [`02-implementation-guide.md`](06-backend-laravel/02-implementation-guide.md) | ⭐ الگوهای کد، تست، بازبینی |

### ۰۷ — موبایل

| سند | موضوع |
|---|---|
| [`01-architecture.md`](07-mobile-flutter/01-architecture.md) | Riverpod، شبکه، امنیت، آفلاین |
| [`02-screens.md`](07-mobile-flutter/02-screens.md) | نقشه ناوبری، طرح صفحات |

### ۰۸ — وب

| سند | موضوع |
|---|---|
| [`01-web-panels.md`](08-frontend-web/01-web-panels.md) | ترمینال معاملاتی، پنل ادمین |

### ۰۹ — عملیات

| سند | موضوع |
|---|---|
| [`01-deployment.md`](09-operations/01-deployment.md) | استقرار، مانیتورینگ، بکاپ، Runbook |

### ۱۰ — انطباق

| سند | موضوع |
|---|---|
| [`01-regulatory.md`](10-compliance/01-regulatory.md) | ⚠️ مسدودکننده‌های حقوقی، سیاست‌ها، حسابرسی |

### ۱۱ — پیوست

| سند | موضوع |
|---|---|
| [`01-formulas.md`](11-appendix/01-formulas.md) | ⭐ ۲۴ فرمول با مثال عددی |
| [`02-state-machines.md`](11-appendix/02-state-machines.md) | ۱۳ ماشین حالت کامل |
| [`03-worked-examples.md`](11-appendix/03-worked-examples.md) | ⭐ ۷ سناریوی end-to-end با ثبت‌های دفتر |

---

## اسناد کلیدی (اگر فقط ۵ سند بخوانید)

</div>

```
۱. 03-domain/03-ledger.md            دفتر کل — قلب سیستم
۲. 11-appendix/03-worked-examples.md همه‌چیز در عمل
۳. 02-architecture/05-adr.md         چرا این تصمیمات
۴. 11-appendix/01-formulas.md        مرجع محاسباتی
۵. 10-compliance/01-regulatory.md    آنچه می‌تواند پروژه را متوقف کند
```

<div dir="rtl">

---

## پنج اصل غیرقابل‌مذاکره

هر تغییری در این مستندات یا کد باید با این پنج اصل سازگار باشد:

</div>

```
۱. Ledger مبنای حقیقت است — append-only، هرگز UPDATE
۲. مالکیت از محل نگهداری جداست
۳. یک موتور محاسبات واحد برای کل سیستم
۴. معامله ≠ تسویه — موتور تسویه مستقل است
۵. هیچ رکورد مالی حذف نمی‌شود — فقط Reversal
```

<div dir="rtl">

---

## نگهداری مستندات

| تغییر | سند(های) که باید به‌روز شود |
|---|---|
| تصمیم معماری جدید | `02-architecture/05-adr.md` |
| تغییر مدل داده | `04-data/01-erd.md` + `04-data/02-schema-mysql.md` |
| endpoint جدید | `05-api/02-endpoints.md` + OpenAPI |
| فرمول جدید یا تغییر گِردکردن | `11-appendix/01-formulas.md` + `03-domain/08` |
| وضعیت جدید در ماشین حالت | `11-appendix/02-state-machines.md` |
| قابلیت جدید | `01-product/03-feature-map.md` |
| رویداد جدید | `02-architecture/03-events.md` |
| اصطلاح جدید | `00-overview/02-glossary.md` |

**قاعده:** قابلیتی که مستند نشده، تحویل‌شده محسوب نمی‌شود.

</div>
