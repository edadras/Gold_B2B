<div dir="rtl">

# ۱. استقرار و عملیات

## ۱.۱ محیط‌ها

| محیط | هدف | داده |
|---|---|---|
| `local` | توسعه | مصنوعی (seeder) |
| `dev` | یکپارچگی | مصنوعی |
| `staging` | تست پذیرش، آزمون بار | مصنوعی با حجم واقعی |
| `production` | عملیات واقعی | واقعی |

> ⛔ **داده تولید هرگز به محیط دیگری کپی نمی‌شود.**
> اگر برای اشکال‌زدایی لازم است، داده باید ناشناس‌سازی شود
> (کد ملی، IBAN، نام، مبالغ با ضریب تصادفی).

---

## ۱.۲ توپولوژی تولید

</div>

```
                        ┌──────────────┐
                        │  CDN / WAF   │
                        └───────┬──────┘
                                │
                        ┌───────▼──────┐
                        │Load Balancer │
                        │  TLS 1.3     │
                        └───────┬──────┘
                                │
        ┌───────────────┬───────┴───────┬───────────────┐
        ▼               ▼               ▼               ▼
  ┌──────────┐   ┌──────────┐   ┌──────────┐   ┌──────────┐
  │  app-1   │   │  app-2   │   │ reverb-1 │   │ reverb-2 │
  │ nginx +  │   │ nginx +  │   │  WS      │   │  WS      │
  │ php-fpm  │   │ php-fpm  │   └──────────┘   └──────────┘
  └──────────┘   └──────────┘
        │               │
        └───────┬───────┘
                │
  ┌─────────────┼─────────────────────────────────┐
  ▼             ▼                                 ▼
┌──────────┐ ┌──────────┐                  ┌──────────────┐
│worker-1  │ │worker-2  │                  │  scheduler   │
│settlement│ │notif+rep │                  │  (تک نمونه)  │
│+ledger   │ │+risk     │                  └──────────────┘
└──────────┘ └──────────┘
        │             │
        └──────┬──────┘
               │
  ┌────────────┼────────────┬─────────────┐
  ▼            ▼            ▼             ▼
┌────────┐ ┌────────┐ ┌──────────┐ ┌────────────┐
│ MySQL  │ │ MySQL  │ │  Redis   │ │ S3 Storage │
│Primary │ │Replica │ │ Sentinel │ │            │
└────────┘ └────────┘ └──────────┘ └────────────┘
```

<div dir="rtl">

### تفکیک worker

</div>

```
worker-settlement   ► صف settlement, ledger-sync
                      ۴ فرآیند، اولویت بالا
                      ⚠️ باید همیشه در دسترس باشد

worker-general      ► صف notification, broadcast, accounting, risk
                      ۶ فرآیند

worker-reporting    ► صف reporting
                      ۲ فرآیند، timeout بلند

scheduler           ► فقط یک نمونه در کل خوشه
                      (وگرنه job تکراری اجرا می‌شود)
```

<div dir="rtl">

---

## ۱.۳ فرآیند استقرار (Zero-Downtime)

</div>

```
۱) بررسی پیش از استقرار
   ├── CI سبز
   ├── migration بازبینی‌شده
   ├── تست روی staging
   └── بکاپ تازه گرفته شده

۲) فعال‌سازی حالت نگهداری (فقط اگر migration شکننده دارد)
   php artisan down --render="maintenance" --retry=60 \
       --secret="..." --except=/health

۳) دریافت کد جدید
   git fetch && git checkout <tag>

۴) نصب وابستگی‌ها
   composer install --no-dev --optimize-autoloader --no-interaction

۵) اجرای migration
   php artisan migrate --force
   ⚠️ روی جدول‌های بزرگ: gh-ost یا pt-online-schema-change

۶) build دارایی‌های frontend
   npm ci && npm run build

۷) بهینه‌سازی
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan event:cache

۸) راه‌اندازی مجدد worker (graceful)
   php artisan queue:restart
   ⚠️ worker جاری کار فعلی را تمام می‌کند و سپس متوقف می‌شود

۹) راه‌اندازی مجدد php-fpm
   systemctl reload php8.3-fpm

۱۰) خروج از حالت نگهداری
    php artisan up

۱۱) بررسی پس از استقرار
    ├── /health سبز
    ├── ثبت یک سفارش آزمایشی و لغو آن
    ├── بررسی صف‌ها
    ├── بررسی نرخ خطا در ۵ دقیقه
    └── اجرای ledger:reconcile روی نمونه
```

<div dir="rtl">

### استقرار سبز/آبی برای تغییرات بزرگ

</div>

```
محیط سبز (فعلی)  ──── ۱۰۰٪ ترافیک
محیط آبی (جدید)  ──── ۰٪

۱) استقرار روی آبی
۲) تست دود روی آبی با ترافیک صفر
۳) انتقال تدریجی: ۵٪ ► ۲۵٪ ► ۵۰٪ ► ۱۰۰٪
۴) نظارت در هر مرحله
۵) در صورت مشکل: بازگشت فوری به سبز

⚠️ محدودیت: هر دو محیط به یک دیتابیس متصل‌اند.
   بنابراین migration باید با هر دو نسخه کد سازگار باشد
   (expand-contract pattern).
```

<div dir="rtl">

### الگوی Expand-Contract برای migration

</div>

```
تغییر: نام ستون price ► price_rial

❌ اشتباه (یک مرحله):
   ALTER TABLE orders RENAME COLUMN price TO price_rial;
   ► کد قدیمی می‌شکند

✅ درست (سه استقرار):

استقرار ۱ — Expand
   ALTER TABLE orders ADD COLUMN price_rial BIGINT NULL;
   کد: می‌خواند از price، می‌نویسد در هر دو

استقرار ۲ — Migrate
   UPDATE orders SET price_rial = price WHERE price_rial IS NULL;
   کد: می‌خواند از price_rial، می‌نویسد در هر دو

استقرار ۳ — Contract
   کد: فقط price_rial
   ALTER TABLE orders DROP COLUMN price;
```

<div dir="rtl">

---

## ۱.۴ متغیرهای محیطی

</div>

```bash
# .env.production  (نمونه — مقادیر واقعی در secret manager)

APP_NAME="Gold B2B"
APP_ENV=production
APP_DEBUG=false                  # ⛔ هرگز true در تولید
APP_KEY=base64:...
APP_URL=https://api.goldb2b.ir
APP_TIMEZONE=UTC                 # ذخیره UTC، نمایش تهران

# دیتابیس
DB_CONNECTION=mysql
DB_HOST=mysql-primary.internal
DB_PORT=3306
DB_DATABASE=goldb2b
DB_USERNAME=app
DB_PASSWORD=...

DB_READ_HOST=mysql-replica.internal

# Redis
REDIS_HOST=redis-sentinel.internal
REDIS_PASSWORD=...
REDIS_CLIENT=phpredis

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=30              # دقیقه

# WebSocket
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST=ws.goldb2b.ir
REVERB_PORT=443
REVERB_SCHEME=https

# ذخیره‌سازی
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=...
AWS_BUCKET=goldb2b-documents
AWS_USE_PATH_STYLE_ENDPOINT=true

# رمزنگاری
BLIND_INDEX_KEY=...              # برای جستجوی کد ملی
DOCUMENT_ENCRYPTION_KEY=...

# اعلان
FCM_SERVER_KEY=...
SMS_PROVIDER=...
SMS_API_KEY=...

# قیمت
PRICE_SOURCE_PRIMARY_URL=...
PRICE_SOURCE_PRIMARY_KEY=...
PRICE_SOURCE_SECONDARY_URL=...

# مانیتورینگ
SENTRY_LARAVEL_DSN=...
SENTRY_TRACES_SAMPLE_RATE=0.1
LOG_CHANNEL=stack
LOG_LEVEL=info                   # نه debug در تولید

# کسب‌وکار
MARKET_OPEN_TIME=09:00
MARKET_CLOSE_TIME=17:30
LEDGER_HASH_CHAIN=true
```

<div dir="rtl">

### مدیریت راز

</div>

```
⛔ هرگز:
   · راز در مخزن گیت
   · راز در Dockerfile
   · راز در لاگ
   · راز در پیام خطا

✅ همیشه:
   · Vault / AWS SSM / Secret Manager
   · تزریق در زمان اجرا
   · چرخش دوره‌ای
   · دسترسی حداقلی
   · gitleaks در CI
```

<div dir="rtl">

---

## ۱.۵ مانیتورینگ

### متریک‌های کاربردی

</div>

```
سلامت API
  ├── نرخ درخواست (req/s)
  ├── نرخ خطا (٪ 5xx)
  ├── تأخیر p50 / p95 / p99
  └── تأخیر به تفکیک endpoint

معاملات
  ├── سفارش‌های ثبت‌شده در دقیقه
  ├── معاملات اجراشده در دقیقه
  ├── تأخیر تطبیق p95
  ├── نرخ رد سفارش
  └── عمق دفتر سفارش

تسویه  ⚠️ حیاتی
  ├── تسویه‌های باز
  ├── تسویه‌های سررسیدگذشته
  ├── میانگین زمان تسویه
  └── نرخ نکول

دفتر  ⛔ بحرانی
  ├── تعداد مغایرت (باید همیشه صفر باشد)
  ├── مدت اجرای reconcile
  ├── انحراف بقای جرم
  └── وضعیت زنجیره hash

زیرساخت
  ├── عمق صف به تفکیک صف
  ├── failed_jobs
  ├── اتصالات دیتابیس
  ├── تأخیر replication
  ├── حافظه Redis
  ├── اتصالات WebSocket
  └── فضای دیسک
```

<div dir="rtl">

### هشدارها

</div>

```
⛔ بحرانی — تماس فوری (۲۴/۷)
   · مغایرت دفتر کل                     > ۰
   · بقای جرم نقض شده                   > ۰
   · شکست زنجیره hash                   > ۰
   · نرخ خطای API                       > ۵٪ به مدت ۵ دقیقه
   · دیتابیس در دسترس نیست
   · عمق صف settlement                  > ۵۰۰
   · شکست بکاپ

🔴 بالا — اعلان فوری (ساعات کاری)
   · تسویه سررسیدگذشته                  > ۵
   · تأخیر p95 API                      > ۱ ثانیه
   · failed_jobs                        > ۱۰
   · منبع قیمت اصلی از کار افتاد
   · تأخیر replication                  > ۳۰ ثانیه
   · پرچم AML بحرانی
   · فضای دیسک                          > ۸۰٪

🟡 متوسط — بررسی روزانه
   · نرخ رد سفارش                       > ۱۰٪
   · عمق صف notification                > ۱۰۰۰
   · مجوز عضو منقضی‌شده
   · نرخ مثبت کاذب AML                  > ۷۰٪
```

<div dir="rtl">

### پشته مانیتورینگ

</div>

```
متریک  ► Prometheus + Grafana
لاگ    ► Loki (یا ELK)
خطا    ► Sentry
Trace  ► OpenTelemetry (اختیاری)
صف     ► Laravel Horizon
Uptime ► سرویس بیرونی (Pingdom/UptimeRobot)
```

<div dir="rtl">

---

## ۱.۶ پشتیبان‌گیری

</div>

```
دیتابیس
  ├── snapshot کامل روزانه          نگهداری ۳۰ روز
  ├── snapshot هفتگی                نگهداری ۱۲ هفته
  ├── snapshot ماهانه               نگهداری ۷ سال
  ├── binlog پیوسته (PITR)          نگهداری ۷ روز
  └── replica تأخیری ۱ ساعته        ◄── محافظ در برابر DROP اشتباهی

فایل (S3)
  ├── versioning فعال
  ├── replication به منطقه دیگر
  └── lifecycle: انتقال به بایگانی سرد پس از ۱ سال

پیکربندی
  └── IaC در گیت + رمزهای رمزنگاری‌شده

⚠️ بکاپ خارج از محیط تولید و با کلید رمزنگاری جداگانه ذخیره شود
⚠️ DBA تولید نباید به کلید بکاپ دسترسی داشته باشد
```

<div dir="rtl">

### تست بازیابی — الزامی

</div>

```
ماهانه:
  ۱) بازیابی آخرین بکاپ در محیط ایزوله
  ۲) اجرای migration
  ۳) اجرای ledger:reconcile
  ۴) بررسی صحت داده (تعداد رکورد، مانده‌ها)
  ۵) ثبت مدت زمان بازیابی
  ۶) گزارش مکتوب

⚠️ بکاپی که تست بازیابی نشده، بکاپ نیست.
```

<div dir="rtl">

---

## ۱.۷ برنامه تداوم کسب‌وکار

</div>

```
هدف‌ها:
  RPO (حداکثر داده از دست‌رفته)   : ۵ دقیقه
  RTO (حداکثر زمان بازیابی)       : ۲ ساعت

سناریوهای شکست:

۱) از کار افتادن یک app server
   ► LB خودکار حذف می‌کند
   ► بدون قطعی

۲) از کار افتادن MySQL primary
   ► ارتقای replica به primary
   ► ~۵ دقیقه قطعی
   ⚠️ بازار باید متوقف شود تا اطمینان از یکپارچگی

۳) از کار افتادن Redis
   ► Sentinel failover
   ► صف‌ها ممکن است job از دست بدهند
   ⚠️ بررسی دستی failed_jobs پس از بازیابی

۴) خرابی داده (bug یا خطای انسانی)
   ► توقف فوری بازار
   ► شناسایی محدوده آسیب از Audit Log
   ► بازیابی PITR تا لحظه قبل از خرابی
   ► بازپخش تراکنش‌های سالم
   ⚠️ Ledger append-only اینجا نجات‌دهنده است

۵) از دست رفتن کل منطقه
   ► بازیابی از بکاپ در منطقه دیگر
   ► RTO ~۴ ساعت
```

<div dir="rtl">

### توقف اضطراری بازار

</div>

```bash
# دستور توقف فوری — در دسترس تیم on-call
php artisan market:halt --reason="..." --notify-all

# اثر:
#   · همه ابزارها ► PAUSED
#   · ثبت سفارش جدید مسدود
#   · لغو سفارش همچنان مجاز
#   · تسویه‌های در جریان ادامه می‌یابد
#   · اعلان فوری به همه اعضا
#   · ثبت در Audit با دلیل
```

<div dir="rtl">

---

## ۱.۸ Runbook — رخدادهای رایج

### مغایرت دفتر کل ⛔

</div>

```
۱) توقف فوری بازار
   php artisan market:halt --reason="ledger discrepancy investigation"

۲) انجماد سازمان متأثر
   php artisan org:freeze {orgId}

۳) شناسایی محدوده
   php artisan ledger:diagnose {accountId}
   ► آخرین نقطه‌ای که مانده درست بود کجاست؟

۴) بررسی Audit Log
   کدام عملیات بین آن نقطه و اکنون انجام شده؟

۵) بررسی failed_jobs
   آیا listener مالی شکست خورده؟

۶) تعیین علت:
   ├── باگ کد        ► رفع + استقرار + اصلاح داده
   ├── job ناتمام    ► اجرای مجدد
   ├── دستکاری       ► رخداد امنیتی
   └── نامشخص        ► تشدید فوری

۷) اصلاح با Reversal یا Adjustment (تأیید دوگانه)

۸) اجرای reconcile مجدد و تأیید

۹) بازگشایی بازار

۱۰) گزارش پس از رخداد ظرف ۴۸ ساعت
```

<div dir="rtl">

### صف تسویه پر شده

</div>

```
۱) بررسی وضعیت
   php artisan horizon:status
   redis-cli LLEN queues:settlement

۲) بررسی failed_jobs
   php artisan queue:failed

۳) اگر worker متوقف است:
   systemctl status worker-settlement
   systemctl restart worker-settlement

۴) اگر job‌ها کند هستند:
   ► بررسی slow query log
   ► بررسی قفل دیتابیس: SHOW ENGINE INNODB STATUS

۵) افزایش موقت worker:
   php artisan queue:work --queue=settlement (نمونه اضافه)

۶) اگر job معیوب مسدود کرده:
   php artisan queue:forget {id}
   ► سپس بررسی دستی آن تسویه
```

<div dir="rtl">

### منبع قیمت از کار افتاد

</div>

```
۱) بررسی وضعیت منابع
   php artisan pricing:status

۲) اگر منبع ثانویه سالم است ► خودکار سوئیچ شده، فقط تأیید کن

۳) اگر همه منابع قطع‌اند:
   ► سیستم خودکار پس از ۵ دقیقه بازار را متوقف می‌کند
   ► گزینه: ورود دستی قیمت
     php artisan pricing:manual --ounce=2650.40 --usd=620000
   ► اعلان صریح به کاربران که قیمت دستی است

۴) پیگیری با تأمین‌کننده

۵) پس از بازگشت: بررسی انحراف و بازگشایی
```

<div dir="rtl">

---

## ۱.۹ چک‌لیست پیش از راه‌اندازی تولید

</div>

```
زیرساخت
[ ] TLS با گواهی معتبر + HSTS
[ ] WAF فعال
[ ] Rate limit در سطح LB و اپلیکیشن
[ ] بکاپ خودکار + تست بازیابی موفق
[ ] replica تأخیری راه‌اندازی شده
[ ] مانیتورینگ و هشدار فعال
[ ] on-call تعریف شده

اپلیکیشن
[ ] APP_DEBUG=false
[ ] لاگ در سطح info، نه debug
[ ] همه cacheها ساخته شده
[ ] REVOKE UPDATE/DELETE روی ledger_entries و audit_logs
[ ] حساب‌های سیستمی ساخته شده
[ ] تنظیمات کارمزد و سقف پیکربندی شده
[ ] تقویم کاری وارد شده

امنیت
[ ] تست نفوذ انجام شده و یافته‌ها رفع شده
[ ] رازها در secret manager
[ ] gitleaks در CI
[ ] composer audit پاک
[ ] 2FA برای همه کارکنان اجباری
[ ] دسترسی SSH فقط با bastion + MFA

کسب‌وکار
[ ] تأیید حقوقی مدل کسب‌وکار
[ ] توافق‌نامه عضویت نهایی و مکتوب
[ ] فرآیند KYC تمرین‌شده
[ ] فرآیند رسیدگی به اختلاف تعریف‌شده
[ ] بیمه خزانه فعال
[ ] پشتیبانی و کانال ارتباطی آماده
[ ] مستندات کاربری آماده
```

</div>
