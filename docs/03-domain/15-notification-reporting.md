<div dir="rtl">

# ۱۵. اعلان و گزارش‌گیری

# بخش الف — موتور اعلان

## ۱۵.۱ کانال‌ها

| کانال | کاربرد | تأخیر قابل قبول |
|---|---|---|
| **In-App** | همه اعلان‌ها | فوری |
| **Push** (FCM/APNs) | رویدادهای نیازمند اقدام | < ۵ ثانیه |
| **SMS** | بحرانی و مالی | < ۳۰ ثانیه |
| **Email** | گزارش‌ها و اسناد | < ۵ دقیقه |
| **WebSocket** | به‌روزرسانی زنده UI | < ۱ ثانیه |
| **Webhook** | یکپارچگی با نرم‌افزار عضو | < ۱۰ ثانیه |

---

## ۱۵.۲ کاتالوگ اعلان‌ها

### دسته معاملات

| کد | متن نمونه | کانال پیش‌فرض | اولویت |
|---|---|---|---|
| `ORDER_PLACED` | سفارش فروش ۳۰۰ گرم ثبت شد | In-App | عادی |
| `ORDER_FILLED` | سفارش شما اجرا شد — ۳۰۰ گرم @ ۷۸,۵۰۰,۰۰۰ | Push + In-App | بالا |
| `ORDER_PARTIAL` | سفارش شما جزئاً اجرا شد — ۱۵۰ از ۳۰۰ گرم | Push + In-App | بالا |
| `ORDER_CANCELLED` | سفارش شما لغو شد | In-App | عادی |
| `ORDER_EXPIRED` | سفارش شما منقضی شد | In-App | عادی |
| `ORDER_REJECTED` | سفارش رد شد — سقف روزانه | Push + In-App | بالا |
| `OTC_OFFER_RECEIVED` | پیشنهاد جدید از بنکداری پارس | Push + In-App | بالا |
| `OTC_OFFER_ACCEPTED` | پیشنهاد شما پذیرفته شد | Push + In-App | بالا |
| `RFQ_RECEIVED` | درخواست قیمت ۵ کیلوگرم دریافت شد | Push + In-App | بالا |
| `RFQ_QUOTED` | ۳ پیشنهاد برای درخواست شما رسید | Push + In-App | بالا |
| `RFQ_ACCEPTED` | پیشنهاد شما پذیرفته شد | Push + SMS | بحرانی |
| `RFQ_EXPIRING` | ۳ دقیقه تا انقضای درخواست قیمت | Push | بالا |

### دسته تسویه

| کد | متن نمونه | کانال | اولویت |
|---|---|---|---|
| `SETTLEMENT_OPENED` | تسویه STL-88231 آغاز شد — مهلت ۱۷:۰۰ | In-App | عادی |
| `PAYMENT_REQUIRED` | پرداخت ۱۹.۶۵ میلیارد ریال تا ۱۷:۰۰ | Push + In-App | بالا |
| `PAYMENT_DECLARED` | طرف مقابل اعلام پرداخت کرد — تأیید کنید | Push + SMS | بحرانی |
| `PAYMENT_CONFIRMED` | دریافت وجه تأیید شد | Push + In-App | بالا |
| `GOLD_TRANSFERRED` | ۲۵۰ گرم به حساب شما منتقل شد | Push + In-App | بالا |
| `SETTLEMENT_COMPLETED` | تسویه کامل شد | Push + In-App | عادی |
| `SETTLEMENT_DUE_SOON` | ۲ ساعت تا مهلت تسویه | Push + SMS | بالا |
| `SETTLEMENT_OVERDUE` | مهلت تسویه گذشت | Push + SMS | بحرانی |
| `SETTLEMENT_DEFAULTED` | تسویه نکول شد | Push + SMS | بحرانی |
| `NETTING_PROPOSED` | پیشنهاد تهاتر — ۳ تعهد ◄ ۱ انتقال | Push + In-App | بالا |
| `NETTING_EXECUTED` | تهاتر اجرا شد | In-App | عادی |

### دسته دارایی

| کد | متن نمونه | کانال | اولویت |
|---|---|---|---|
| `GOLD_RESERVED` | ۲۵۰ گرم طلای شما رزرو شد | In-App | عادی |
| `GOLD_RELEASED` | رزرو ۲۵۰ گرم آزاد شد | In-App | عادی |
| `LOW_GOLD_BALANCE` | موجودی طلای شما زیر حد هشدار | Push + In-App | بالا |
| `LOW_RIAL_BALANCE` | موجودی ریالی شما زیر حد هشدار | Push + In-App | بالا |
| `VAULT_DEPOSIT_DONE` | ۱,۵۰۰ گرم به خزانه اضافه شد | Push + SMS | بالا |
| `VAULT_WITHDRAWAL_APPROVED` | درخواست برداشت تأیید شد | Push + SMS | بالا |
| `ASSAY_COMPLETED` | ری‌گیری تکمیل شد — عیار ۹۹۵ | Push + In-App | بالا |
| `ASSAY_VARIANCE` | ⚠️ عیار متفاوت از اعلامی — ۹۸۵ به‌جای ۹۹۵ | Push + SMS | بحرانی |

### دسته حساب و انطباق

| کد | متن نمونه | کانال | اولویت |
|---|---|---|---|
| `KYC_APPROVED` | حساب شما تأیید و فعال شد | Push + SMS | بالا |
| `KYC_INFO_REQUIRED` | مدارک تکمیلی نیاز است | Push + SMS | بالا |
| `KYC_REJECTED` | درخواست عضویت رد شد | Push + SMS | بالا |
| `LICENSE_EXPIRING_30` | مجوز شما ۳۰ روز دیگر منقضی می‌شود | In-App + Email | عادی |
| `LICENSE_EXPIRING_10` | مجوز شما ۱۰ روز دیگر منقضی می‌شود | Push + SMS | بالا |
| `LICENSE_EXPIRED` | ⚠️ مجوز شما منقضی شد — معامله متوقف | Push + SMS | بحرانی |
| `LIMIT_INCREASED` | سقف معاملاتی شما افزایش یافت | Push | عادی |
| `ACCOUNT_RESTRICTED` | حساب شما محدود شد | Push + SMS | بحرانی |
| `NEW_LOGIN` | ورود جدید از دستگاه ناشناس | Push + SMS | بالا |
| `TIER_UPGRADED` | 🎉 سطح شما به طلایی ارتقا یافت | Push + In-App | عادی |

### دسته اختلاف

| کد | متن | کانال | اولویت |
|---|---|---|---|
| `DISPUTE_OPENED_AGAINST` | اختلاف علیه شما ثبت شد — مهلت پاسخ ۲۴ ساعت | Push + SMS | بحرانی |
| `DISPUTE_REPLY_DUE` | ۴ ساعت تا پایان مهلت پاسخ | Push + SMS | بحرانی |
| `DISPUTE_MESSAGE` | پیام جدید در پرونده اختلاف | Push | بالا |
| `DISPUTE_RESOLVED` | رأی پرونده صادر شد | Push + SMS | بحرانی |

---

## ۱۵.۳ مدل داده

</div>

```sql
CREATE TABLE notifications (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id   BIGINT UNSIGNED NOT NULL,
  user_id           BIGINT UNSIGNED NULL,      -- NULL = همه کاربران سازمان
  code              VARCHAR(50) NOT NULL,
  category          VARCHAR(30) NOT NULL,
  priority          ENUM('LOW','NORMAL','HIGH','CRITICAL') NOT NULL,
  title             VARCHAR(200) NOT NULL,
  body              VARCHAR(1000) NOT NULL,
  action_type       VARCHAR(50) NULL,          -- 'open_settlement'
  action_payload    JSON NULL,                 -- {"settlement_id": 88231}
  subject_type      VARCHAR(50) NULL,
  subject_id        BIGINT UNSIGNED NULL,
  read_at           TIMESTAMP NULL,
  created_at        TIMESTAMP NOT NULL,
  KEY idx_org_created (organization_id, created_at),
  KEY idx_user_unread (user_id, read_at),
  KEY idx_code (code)
) ENGINE=InnoDB;

CREATE TABLE notification_deliveries (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  notification_id   BIGINT UNSIGNED NOT NULL,
  channel           ENUM('PUSH','SMS','EMAIL','WEBHOOK') NOT NULL,
  destination       VARCHAR(255) NOT NULL,     -- token/phone/email (hash)
  status            ENUM('QUEUED','SENT','DELIVERED','FAILED','SKIPPED') NOT NULL,
  provider_ref      VARCHAR(100) NULL,
  attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error             VARCHAR(500) NULL,
  sent_at           TIMESTAMP NULL,
  delivered_at      TIMESTAMP NULL,
  KEY idx_notification (notification_id),
  KEY idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE notification_preferences (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT UNSIGNED NOT NULL,
  category          VARCHAR(30) NOT NULL,
  in_app            BOOLEAN NOT NULL DEFAULT TRUE,
  push              BOOLEAN NOT NULL DEFAULT TRUE,
  sms               BOOLEAN NOT NULL DEFAULT FALSE,
  email             BOOLEAN NOT NULL DEFAULT FALSE,
  quiet_hours_from  TIME NULL,
  quiet_hours_to    TIME NULL,
  UNIQUE KEY uq_user_category (user_id, category)
) ENGINE=InnoDB;
```

<div dir="rtl">

---

## ۱۵.۴ قواعد ارسال

</div>

```
۱) اولویت CRITICAL همیشه ارسال می‌شود
   ► تنظیمات کاربر و ساعت سکوت را نادیده می‌گیرد
   ► دلیل: مسائل مالی و مهلت‌دار

۲) ساعت سکوت فقط برای اولویت LOW و NORMAL

۳) تجمیع (Batching)
   اگر بیش از ۵ اعلان هم‌نوع در ۵ دقیقه:
   ► یک اعلان تجمیعی: «۷ سفارش شما اجرا شد»

۴) عدم تکرار
   یک رویداد ► حداکثر یک اعلان به هر کاربر
   (کلید یکتا: notification_code + subject_id + user_id)

۵) هدف‌گیری نقش‌محور
   PAYMENT_REQUIRED  ► TREASURER, OWNER
   ORDER_FILLED      ► TRADER, OWNER
   KYC_*             ► OWNER فقط
   DISPUTE_*         ► OWNER, MANAGER

۶) SMS فقط برای:
   · بحرانی
   · مالی با اثر فوری
   · امنیتی
   ► هزینه دارد، بی‌جهت استفاده نشود
```

<div dir="rtl">

---

## ۱۵.۵ پیاده‌سازی

</div>

```php
namespace App\Modules\Notification\Application;

final class NotificationDispatcher
{
    public function dispatch(NotificationSpec $spec): void
    {
        $recipients = $this->resolveRecipients($spec);

        foreach ($recipients as $user) {
            // جلوگیری از تکرار
            if ($this->alreadySent($spec, $user)) {
                continue;
            }

            $notification = Notification::create([
                'organization_id' => $spec->organizationId,
                'user_id'         => $user->id,
                'code'            => $spec->code,
                'category'        => $spec->category,
                'priority'        => $spec->priority,
                'title'           => $spec->title,
                'body'            => $spec->body,
                'action_type'     => $spec->actionType,
                'action_payload'  => $spec->actionPayload,
                'subject_type'    => $spec->subjectType,
                'subject_id'      => $spec->subjectId,
            ]);

            foreach ($this->channelsFor($user, $spec) as $channel) {
                dispatch(new SendNotification($notification->id, $channel))
                    ->onQueue('notification');
            }
        }
    }

    private function channelsFor(User $user, NotificationSpec $spec): array
    {
        // بحرانی: همه کانال‌ها بدون توجه به تنظیمات
        if ($spec->priority === Priority::CRITICAL) {
            return ['PUSH', 'SMS'];
        }

        $prefs = $user->notificationPreference($spec->category);

        if ($this->isQuietHours($prefs) && $spec->priority !== Priority::HIGH) {
            return [];   // فقط In-App
        }

        return array_filter([
            $prefs->push  ? 'PUSH'  : null,
            $prefs->sms   ? 'SMS'   : null,
            $prefs->email ? 'EMAIL' : null,
        ]);
    }
}
```

<div dir="rtl">

---

# بخش ب — گزارش‌گیری

## ۱۵.۶ فهرست گزارش‌ها

### گزارش‌های عضو

| گزارش | دوره | خروجی |
|---|---|---|
| گردش طلا | روزانه/ماهانه/سفارشی | Excel, PDF, API |
| گردش ریال | همان | همان |
| فهرست معاملات | همان | همان |
| دفتر کل طلا | همان | همان |
| دفتر کل ریال | همان | همان |
| صورت‌حساب طرف‌حساب | سفارشی | همان |
| سود و زیان | ماهانه | همان |
| سود روزانه | روزانه | همان |
| موجودی lotها | لحظه‌ای | همان |
| ارزش روز موجودی | لحظه‌ای | همان |
| تسویه‌های باز | لحظه‌ای | همان |
| کارمزدهای پرداختی | ماهانه | همان |
| گزارش خزانه | ماهانه | همان |
| تراز آزمایشی | ماهانه | همان |

### گزارش‌های پلتفرم

| گزارش | مخاطب |
|---|---|
| حجم و ارزش معاملات | مدیریت |
| توزیع اعضا و فعالیت | مدیریت |
| نرخ تسویه و نکول | ریسک |
| موجودی خزانه و تطبیق | خزانه |
| پرچم‌های AML | انطباق |
| اختلافات و روند | انطباق |
| درآمد کارمزد | مالی |
| سلامت سیستم | فنی |

---

## ۱۵.۷ ساختار گزارش گردش

قالب استاندارد برای همه گزارش‌های گردش:

</div>

```
┌──────────────────────────────────────────────────────┐
│  Opening Balance      مانده ابتدای دوره               │
│  ─────────────────────────────────────────────────    │
│  + Inflows            ورودی‌ها (به تفکیک منبع)        │
│  − Outflows           خروجی‌ها (به تفکیک مقصد)        │
│  ± Adjustments        تعدیلات (با ذکر دلیل هر مورد)   │
│  ─────────────────────────────────────────────────    │
│  = Closing Balance    مانده پایان دوره                │
│                                                      │
│  ✓ تطبیق: Closing == محاسبه مستقل از دفتر            │
└──────────────────────────────────────────────────────┘
```

<div dir="rtl">

**قاعده الزامی:** هر گزارش گردش باید در انتها یک **بررسی تطبیق** داشته
باشد که نشان دهد مانده پایانی با محاسبه مستقل از دفتر کل یکی است.
اگر یکی نبود، گزارش با هشدار قرمز نمایش داده می‌شود.

---

## ۱۵.۸ معماری گزارش‌گیری

</div>

```
درخواست گزارش
      │
      ▼
┌─────────────────────────────────┐
│  آیا سبک است؟                    │
│  (< 1000 رکورد، < 2 ثانیه)      │
└──────┬──────────────────┬───────┘
       │ بله              │ خیر
       ▼                  ▼
┌─────────────┐   ┌──────────────────────┐
│ اجرای فوری  │   │  صف reporting        │
│ روی replica │   │  تولید ناهمگام       │
└─────────────┘   └──────────┬───────────┘
                             │
                             ▼
                  ┌──────────────────────┐
                  │ ذخیره در S3          │
                  │ لینک موقت (۲۴ ساعت)  │
                  └──────────┬───────────┘
                             │
                             ▼
                       اعلان به کاربر
```

<div dir="rtl">

### قواعد

</div>

```
· همه گزارش‌ها از read replica خوانده می‌شوند
· به‌جز گزارش‌های لحظه‌ای دفتر که باید از primary باشند
· حداکثر بازه گزارش: ۱ سال در یک درخواست
· Rate limit: ۲۰ گزارش در ساعت برای هر سازمان
· گزارش تولیدشده در S3 با نام تصادفی و لینک امضاشده
· هر تولید گزارش در Audit ثبت می‌شود
```

<div dir="rtl">

---

## ۱۵.۹ جدول‌های تجمیعی (Aggregate Tables)

برای گزارش‌های پرتکرار، محاسبه شبانه:

</div>

```sql
CREATE TABLE daily_org_summary (
  organization_id     BIGINT UNSIGNED NOT NULL,
  summary_date        DATE NOT NULL,

  gold_opening_mg     BIGINT NOT NULL,
  gold_bought_mg      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  gold_sold_mg        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  gold_deposited_mg   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  gold_withdrawn_mg   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  gold_adjustment_mg  BIGINT NOT NULL DEFAULT 0,
  gold_closing_mg     BIGINT NOT NULL,

  rial_opening        BIGINT NOT NULL,
  rial_in             BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rial_out            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rial_fees           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rial_closing        BIGINT NOT NULL,

  trade_count         INT UNSIGNED NOT NULL DEFAULT 0,
  buy_count           INT UNSIGNED NOT NULL DEFAULT 0,
  sell_count          INT UNSIGNED NOT NULL DEFAULT 0,

  realized_pnl        BIGINT NOT NULL DEFAULT 0,
  avg_cost_per_gram   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  closing_market_value BIGINT UNSIGNED NOT NULL DEFAULT 0,

  is_reconciled       BOOLEAN NOT NULL DEFAULT FALSE,
  computed_at         TIMESTAMP NOT NULL,

  PRIMARY KEY (organization_id, summary_date)
) ENGINE=InnoDB;
```

<div dir="rtl">

**ثابت:**

</div>

```
gold_closing = gold_opening + bought + deposited
             − sold − withdrawn + adjustment

اگر برقرار نبود ► is_reconciled = FALSE + هشدار
```

<div dir="rtl">

---

## ۱۵.۱۰ خروجی Excel — نکات

</div>

```
· اعداد به‌صورت عدد ذخیره شوند، نه رشته (تا قابل جمع باشند)
· وزن به گرم با ۳ رقم اعشار
· مبلغ به ریال، بدون جداکننده در سلول (فرمت سلول تنظیم شود)
· تاریخ شمسی به‌صورت رشته + یک ستون تاریخ میلادی برای مرتب‌سازی
· راست‌به‌چپ بودن شیت
· ردیف اول: عنوان گزارش، دوره، تاریخ تولید، نام سازمان
· ردیف آخر: جمع + checksum
· قفل شیت برای جلوگیری از ویرایش تصادفی
```

</div>
