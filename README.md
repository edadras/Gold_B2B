<div dir="rtl">

# Gold B2B — شبکه معاملات و زیرساخت عملیاتی بازار طلای آب‌شده

> **وضعیت پروژه:** فاز مستندسازی و طراحی (Design Phase) — هنوز هیچ کدی نوشته نشده است.
> این مخزن در حال حاضر فقط شامل **مستندات معماری و طراحی محصول** است.

---

## Gold B2B چیست؟

`Gold B2B` یک **زیرساخت معاملاتی و عملیاتی بین‌بنگاهی (B2B)** برای بازار طلای آب‌شده است؛
نه یک «سایت خرید و فروش طلا» و نه یک «اپلیکیشن خرید طلا برای عموم مردم».

مخاطبان این سامانه اعضای صنف هستند:

| مخاطب | نقش در بازار |
|---|---|
| طلافروش (خرده‌فروش) | خرید و فروش آب‌شده برای تأمین/تخلیه موجودی |
| بنکدار (عمده‌فروش) | تأمین‌کننده حجم بالا، بازارساز |
| آبشده‌فروش | ذوب، ری‌گیری، تولید شمش آب‌شده |
| آزمایشگاه ری‌گیری | صدور گواهی عیار (Assay) |
| خزانه / Custodian | نگهداری فیزیکی طلا |
| اپراتور سامانه | تسویه، تطبیق، رسیدگی به اختلاف |

هدف نهایی، تبدیل شبکهٔ پراکنده و تلفنی/پیام‌رسانی معاملات آب‌شده به یک
**سیستم‌عامل شبکه طلا (Gold Network Operating System)** است که در آن سفارش،
معامله، تسویه، حسابداری، انبارداری فیزیکی و مدیریت ریسک روی یک زیرساخت واحد
و قابل حسابرسی (auditable) اجرا می‌شود.

---

## سه لایه محصول

</div>

```
┌──────────────────────────────────────────────────────────────┐
│  CROSS-CUTTING:  KYC · AML · Compliance · Audit · Reporting  │
├──────────────────────────────────────────────────────────────┤
│  LAYER 1 — COMMERCE                                          │
│  Order · Matching · RFQ · OTC · Price Feed                    │
├──────────────────────────────────────────────────────────────┤
│  LAYER 2 — FINANCIAL INFRASTRUCTURE                          │
│  Gold Ledger · Rial Ledger · Clearing · Settlement ·          │
│  Netting · Accounting · Risk & Credit                         │
├──────────────────────────────────────────────────────────────┤
│  LAYER 3 — PHYSICAL GOLD INFRASTRUCTURE                      │
│  Assay · Gold Lot · Custody · Vault · Split/Merge ·            │
│  Genealogy · Delivery                                         │
└──────────────────────────────────────────────────────────────┘
```

<div dir="rtl">

---

## پشتهٔ فنی (Tech Stack)

| لایه | فناوری |
|---|---|
| Backend / API | **Laravel 11 (PHP 8.3)** |
| Database | **MySQL 8.0** (InnoDB, utf8mb4) |
| Cache / Lock / Queue | Redis 7 |
| Realtime | Laravel Reverb (WebSocket) |
| Mobile | **Flutter 3.x** (iOS + Android) |
| Web (Trader/Admin) | Laravel + Inertia + Vue 3 (یا SPA مستقل روی همان API) |
| Object Storage | S3-compatible (اسناد KYC، گواهی ری‌گیری) |
| Search / Analytics | MySQL + (اختیاری) ClickHouse برای گزارش‌های سنگین |

> نکته: در طراحی اولیه `PostgreSQL` مطرح شده بود؛ تصمیم نهایی **MySQL 8** است.
> پیامدهای این انتخاب (نبود `EXCLUDE constraint`، رفتار `DECIMAL`، ایزولاسیون تراکنش)
> در [`docs/04-data/02-schema-mysql.md`](docs/04-data/02-schema-mysql.md) به‌صورت صریح مدیریت شده است.

---

## نقشهٔ مستندات

</div>

| # | سند | موضوع |
|---|---|---|
| **00** | [`00-overview/01-vision.md`](docs/00-overview/01-vision.md) | چشم‌انداز، مسئله، ارزش پیشنهادی |
| | [`00-overview/02-glossary.md`](docs/00-overview/02-glossary.md) | واژه‌نامه فارسی/انگلیسی بازار طلا |
| | [`00-overview/03-market-context.md`](docs/00-overview/03-market-context.md) | نحوهٔ کار امروزی بازار آب‌شده |
| | [`00-overview/04-scope.md`](docs/00-overview/04-scope.md) | دامنه، خارج از دامنه، فرضیات |
| **01** | [`01-product/01-personas-roles.md`](docs/01-product/01-personas-roles.md) | پرسوناها، نقش‌ها، ماتریس دسترسی |
| | [`01-product/02-user-journeys.md`](docs/01-product/02-user-journeys.md) | مسیرهای کاربری end-to-end |
| | [`01-product/03-feature-map.md`](docs/01-product/03-feature-map.md) | نقشه کامل قابلیت‌ها |
| | [`01-product/04-roadmap.md`](docs/01-product/04-roadmap.md) | فازبندی MVP → نسخه کامل |
| **02** | [`02-architecture/01-system-architecture.md`](docs/02-architecture/01-system-architecture.md) | معماری کلان |
| | [`02-architecture/02-modules.md`](docs/02-architecture/02-modules.md) | مرزبندی ماژول‌ها (Modular Monolith) |
| | [`02-architecture/03-events.md`](docs/02-architecture/03-events.md) | Event Bus و قرارداد رویدادها |
| | [`02-architecture/04-security.md`](docs/02-architecture/04-security.md) | امنیت، رمزنگاری، احراز هویت |
| | [`02-architecture/05-adr.md`](docs/02-architecture/05-adr.md) | تصمیمات معماری (ADR) |
| **03** | [`03-domain/`](docs/03-domain/) | ۱۵ سند دامنه: هویت، دفتر کل، بازار، تسویه، خزانه، ریسک، … |
| **04** | [`04-data/01-erd.md`](docs/04-data/01-erd.md) | مدل داده و ERD |
| | [`04-data/02-schema-mysql.md`](docs/04-data/02-schema-mysql.md) | اسکیمای کامل MySQL |
| **05** | [`05-api/`](docs/05-api/) | قراردادهای REST / WebSocket / Webhook |
| **06** | [`06-backend-laravel/`](docs/06-backend-laravel/) | ساختار پروژه Laravel |
| **07** | [`07-mobile-flutter/`](docs/07-mobile-flutter/) | معماری اپ Flutter |
| **08** | [`08-frontend-web/`](docs/08-frontend-web/) | پنل وب معامله‌گر و ادمین |
| **09** | [`09-operations/`](docs/09-operations/) | استقرار، مانیتورینگ، پشتیبان‌گیری، DR |
| **10** | [`10-compliance/`](docs/10-compliance/) | الزامات قانونی، KYC/AML، حسابرسی |
| **11** | [`11-appendix/`](docs/11-appendix/) | فرمول‌ها، ماشین‌های حالت، نمونه داده |

<div dir="rtl">

---

## اصول غیرقابل‌مذاکره طراحی

این پنج اصل در تمام مستندات رعایت شده و هر تغییری باید با آن‌ها سازگار باشد:

1. **Ledger مبنای حقیقت است.** موجودی طلا و ریال هرگز مستقیماً `UPDATE` نمی‌شود؛
   هر تغییر یک سطر `append-only` در دفتر کل ایجاد می‌کند و مانده از روی دفتر
   قابل بازسازی است.
2. **مالکیت از محل نگهداری جداست.** `Owner ≠ Custodian ≠ Physical Location`.
   انتقال مالکیت لزوماً به معنی جابه‌جایی فیزیکی طلا نیست.
3. **یک موتور محاسبات واحد.** فرمول تبدیل وزن/عیار/وزن خالص/مبلغ فقط در
   یک نقطه پیاده‌سازی می‌شود و پنل معامله‌گر، حسابداری، تسویه و گزارش همگی
   از همان استفاده می‌کنند.
4. **معامله ≠ تسویه.** موتور تسویه (Settlement Engine) مستقل از موتور معامله است.
5. **هیچ رکورد مالی حذف نمی‌شود.** اصلاح فقط با ثبت رکورد معکوس (Reversal).

---

## هشدار حقوقی

معاملات برخط طلا در ایران مشمول مقررات اختصاصی است و «دستورالعمل اجرایی خرید و
فروش برخط طلا و نقره» چارچوب مشخصی برای این فعالیت ایجاد کرده است.
**پیش از هرگونه بهره‌برداری عملیاتی، انطباق مدل کسب‌وکار و معماری محصول با
الزامات قانونی، مجوزی و مالیاتی روز باید توسط مشاور حقوقی و نهادهای ذی‌ربط
تأیید شود.** جزئیات در [`docs/10-compliance/01-regulatory.md`](docs/10-compliance/01-regulatory.md).

این مخزن یک سند فنی/طراحی است و مشاورهٔ حقوقی محسوب نمی‌شود.

---

## مشارکت

</div>

```bash
git clone <repo-url>
cd Gold_B2B
# مستندات: پوشه docs/
```

<div dir="rtl">

هر تغییر در مستندات باید:
- با اصول پنج‌گانه بالا سازگار باشد
- در صورت تغییر مدل داده، `04-data/` را هم به‌روز کند
- در صورت تصمیم معماری جدید، یک ADR در `02-architecture/05-adr.md` اضافه کند

</div>
