<div dir="rtl">

# ۱. پنل‌های وب

دو پنل وب جداگانه وجود دارد:

| پنل | مخاطب | فناوری |
|---|---|---|
| **Trader Web** | معامله‌گر، حسابدار، خزانه‌دار عضو | Laravel + Inertia + Vue 3 |
| **Admin Panel** | کارکنان اپراتور سامانه | Filament 3 |

---

# بخش الف — پنل معامله‌گر

## ۱.۱ چرا وب جداگانه از موبایل؟

</div>

```
موبایل ► سرعت و دسترسی در حرکت
وب     ► چگالی اطلاعات و کار عمیق

کارهایی که فقط در وب معنا دارند:
  · مشاهده همزمان چند ابزار
  · جدول‌های بزرگ (دفتر کل، معاملات)
  · گزارش‌های تفصیلی
  · حسابداری
  · مدیریت کاربران و دسترسی
  · KYC و آپلود اسناد
  · عملیات خزانه
  · کیبورد شورتکات برای معامله‌گر حرفه‌ای
```

<div dir="rtl">

---

## ۱.۲ ساختار

</div>

```
resources/js/
├── app.ts
├── Pages/                          ◄── صفحات Inertia
│   ├── Auth/
│   ├── Dashboard/
│   ├── Market/
│   │   ├── Terminal.vue            ◄── ترمینال معاملاتی
│   │   └── Instruments.vue
│   ├── Orders/
│   ├── Trades/
│   ├── Otc/
│   ├── Rfq/
│   ├── Settlements/
│   ├── Ledger/
│   ├── Lots/
│   ├── Vault/
│   ├── Counterparties/
│   ├── Accounting/
│   ├── Reports/
│   ├── Kyc/
│   ├── Team/                       ◄── مدیریت کاربران
│   └── Settings/
│
├── Layouts/
│   ├── AppLayout.vue
│   ├── TerminalLayout.vue          ◄── چیدمان متراکم
│   └── GuestLayout.vue
│
├── Components/
│   ├── Market/
│   │   ├── OrderBook.vue
│   │   ├── PriceTicker.vue
│   │   ├── TradeTape.vue
│   │   ├── OrderForm.vue
│   │   └── PriceChart.vue
│   ├── Data/
│   │   ├── DataTable.vue
│   │   ├── WeightCell.vue
│   │   ├── MoneyCell.vue
│   │   └── StatusBadge.vue
│   └── Common/
│
├── Composables/
│   ├── useWebSocket.ts
│   ├── useOrderBook.ts
│   ├── useBalance.ts
│   └── useFormatting.ts
│
├── Stores/                         ◄── Pinia
│   ├── auth.ts
│   ├── market.ts
│   └── notification.ts
│
└── Utils/
    ├── weight.ts                   ◄── معادل TS از VOها
    ├── money.ts
    └── jalali.ts
```

<div dir="rtl">

---

## ۱.۳ ترمینال معاملاتی

</div>

```
┌────────────────────────────────────────────────────────────────────────────────┐
│ Gold B2B    طلافروشی کریمی 🥈       🟢 بازار باز  ۱۴:۲۳:۱۱      🔔۳   ⚙️  خروج │
├───────────────┬────────────────────────────────────┬───────────────────────────┤
│ ابزارها       │  GOLD-995-T0                       │  ثبت سفارش                 │
│               │  ۷۸,۴۵۰,۰۰۰  ▲ ۰.۴۲٪               │  ┌─────┐┌─────┐            │
│ ▸GOLD-995-T0  │                                    │  │خرید ││فروش│            │
│  GOLD-995-T1  │  ┌──────────────────────────────┐  │  └─────┘└─────┘            │
│  GOLD-750-T0  │  │                              │  │                           │
│  GOLD-900-T0  │  │      [نمودار قیمت]           │  │  نوع  [محدود      ▾]      │
│               │  │                              │  │  مقدار ┌─────────────┐     │
│───────────────│  │                              │  │        │ ۳۰۰.۰۰۰     │     │
│ موجودی        │  └──────────────────────────────┘  │        └─────────────┘     │
│               │   ۱د  ۱ه  ۱م  ۳م  ۱س              │  قیمت  ┌─────────────┐     │
│ طلا           │                                    │        │ ۷۸,۵۰۰,۰۰۰  │     │
│ ۱,۲۴۷.۳۲۰ گرم │  ─── عمق بازار ──────────────────  │        └─────────────┘     │
│ آزاد ۱,۰۴۷.۳۲ │  فروش                              │  اعتبار [تا پایان روز ▾]   │
│ رزرو   ۱۵۰.۰۰ │  ۷۸,۵۲۰,۰۰۰  ۱,۰۰۰ ████████       │                           │
│ تسویه   ۵۰.۰۰ │  ۷۸,۵۰۰,۰۰۰    ۵۰۰ ████           │  ─────────────────────    │
│               │  ۷۸,۴۸۰,۰۰۰    ۳۵۰ ███            │  ناخالص ۲۳,۵۵۰,۰۰۰,۰۰۰    │
│ ریال          │  ─────── ۶۰,۰۰۰ ───────           │  کارمزد     ۲۳,۵۵۰,۰۰۰    │
│ ۴.۲۰ میلیارد  │  ۷۸,۴۲۰,۰۰۰    ۳۰۰ ███            │  ─────────────────────    │
│ آزاد ۴.۲۰ م   │  ۷۸,۴۰۰,۰۰۰    ۲۵۰ ██             │  خالص ۲۳,۵۲۶,۴۵۰,۰۰۰      │
│ رزرو ۱.۵۰ م   │  ۷۸,۳۵۰,۰۰۰    ۸۰۰ █████          │                           │
│               │  خرید                              │  ┌───────────────────┐    │
│───────────────│                                    │  │   ثبت سفارش       │    │
│ سود امروز     ├────────────────────────────────────┤  └───────────────────┘    │
│ +۳۱۸,۲۲۰,۰۰۰  │  سفارش‌های باز (۳)                  │                           │
│               │  ORD-44120 فروش ۳۰۰g ۷۸,۵۰۰,۰۰۰ ✕ │  ─── معاملات اخیر ────    │
│ ⚠️ اقدام لازم │  ORD-44118 خرید ۱۵۰g ۷۸,۴۰۰,۰۰۰ ✕ │  ۱۴:۲۲ ۷۸,۴۸۰,۰۰۰ ۱۰۰ ▲  │
│ ۲ تسویه       │  ORD-44101 فروش ۵۰۰g ۷۸,۶۰۰,۰۰۰ ✕ │  ۱۴:۲۱ ۷۸,۴۸۰,۰۰۰ ۲۵۰ ▼  │
└───────────────┴────────────────────────────────────┴───────────────────────────┘
```

<div dir="rtl">

### کیبورد شورتکات

</div>

```
B / خ       ► فرم خرید
S / ف       ► فرم فروش
Esc         ► پاک کردن فرم
Enter       ► ثبت سفارش (با تأیید)
Ctrl+Enter  ► ثبت بدون تأیید (اگر فعال شده)
↑ / ↓       ► تغییر قیمت به اندازه یک tick
Shift+↑/↓   ► تغییر قیمت ۱۰ tick
Ctrl+A      ► لغو همه سفارش‌ها (با تأیید)
1..9        ► انتخاب ابزار
/           ► جستجو
?           ► راهنمای شورتکات
```

<div dir="rtl">

---

## ۱.۴ جدول داده استاندارد

</div>

```vue
<!-- Components/Data/DataTable.vue -->
<template>
  <div class="data-table" dir="rtl">
    <!-- نوار ابزار -->
    <div class="toolbar">
      <SearchInput v-model="search" />
      <FilterPanel :filters="filters" @change="onFilterChange" />
      <ExportButton :endpoint="exportEndpoint" :params="queryParams" />
    </div>

    <table>
      <thead>
        <tr>
          <th v-for="col in columns" :key="col.key"
              :class="[col.align, { sortable: col.sortable }]"
              @click="col.sortable && toggleSort(col.key)">
            {{ col.label }}
            <SortIcon v-if="col.sortable" :direction="sortFor(col.key)" />
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.id" @click="$emit('rowClick', row)">
          <td v-for="col in columns" :key="col.key" :class="col.align">
            <!-- سلول‌های تخصصی -->
            <WeightCell v-if="col.type === 'weight'" :mg="row[col.key]" />
            <MoneyCell  v-else-if="col.type === 'money'" :rial="row[col.key]" />
            <JalaliCell v-else-if="col.type === 'date'" :iso="row[col.key]" />
            <StatusBadge v-else-if="col.type === 'status'"
                         :status="row[col.key]" :map="col.statusMap" />
            <span v-else>{{ row[col.key] }}</span>
          </td>
        </tr>
      </tbody>
      <tfoot v-if="showTotals">
        <tr>
          <td v-for="col in columns" :key="col.key" :class="col.align">
            <component :is="cellFor(col)" v-if="col.total" :value="totals[col.key]" />
          </td>
        </tr>
      </tfoot>
    </table>

    <CursorPagination :links="links" @navigate="loadPage" />
  </div>
</template>
```

<div dir="rtl">

### سلول وزن و مبلغ

</div>

```vue
<!-- WeightCell.vue -->
<template>
  <span class="tabular-nums" dir="ltr">{{ formatted }}</span>
</template>

<script setup lang="ts">
const props = defineProps<{ mg: number; showUnit?: boolean }>();

const formatted = computed(() => {
  const whole = Math.trunc(props.mg / 1000);
  const frac  = Math.abs(props.mg % 1000);
  const s = `${whole.toLocaleString('en-US')}.${String(frac).padStart(3, '0')}`;
  return props.showUnit ? `${s} گرم` : s;
});
</script>

<style scoped>
.tabular-nums { font-variant-numeric: tabular-nums; }
</style>
```

<div dir="rtl">

> `font-variant-numeric: tabular-nums` باعث می‌شود ارقام در ستون‌ها
> هم‌عرض و تراز باشند — برای جدول‌های مالی ضروری است.

---

## ۱.۵ صفحه دفتر کل

</div>

```
┌───────────────────────────────────────────────────────────────────────────┐
│  دفتر کل طلا                                                              │
│  از ۱۴۰۴/۰۸/۰۱  تا ۱۴۰۴/۰۸/۳۰      [🔍 جستجو]  [فیلتر ▾]  [Excel] [PDF] │
├───────────────────────────────────────────────────────────────────────────┤
│  تاریخ         شرح                        بدهکار    بستانکار      مانده    │
├───────────────────────────────────────────────────────────────────────────┤
│  ۰۸/۰۱ ۰۹:۰۰  مانده افتتاحیه                  —          —    1,000.000  │
│  ۰۸/۰۳ ۱۱:۲۰  خرید TRD-88201 🔗               —   +250.000    1,250.000  │
│  ۰۸/۰۳ ۱۱:۲۰  رزرو سفارش ORD-4412 🔗    −100.000         —    1,150.000  │
│  ۰۸/۰۴ ۱۴:۰۵  لغو سفارش ORD-4412 🔗           —   +100.000    1,250.000  │
│  ۰۸/۰۵ ۱۰:۱۵  فروش TRD-88231 🔗         −300.000         —      950.000  │
│  ۰۸/۰۷ ۱۶:۴۰  خرید TRD-88290 🔗               —   +500.000    1,450.000  │
│  ۰۸/۰۹ ۰۹:۳۰  تعدیل ری‌گیری AS-4599 🔗    −2.680         —    1,447.320  │
│  ۰۸/۱۲ ۱۲:۰۰  تحویل فیزیکی WD-221 🔗    −200.000         —    1,247.320  │
├───────────────────────────────────────────────────────────────────────────┤
│  جمع                                    −602.680   +850.000               │
│  مانده پایانی                                                 1,247.320  │
│  ├── در دسترس                                                 1,047.320  │
│  ├── رزروشده                                                    150.000  │
│  └── در تسویه                                                    50.000  │
├───────────────────────────────────────────────────────────────────────────┤
│  ✅ تطبیق: مانده محاسبه‌شده با مانده ثبت‌شده مطابق است                      │
└───────────────────────────────────────────────────────────────────────────┘
```

<div dir="rtl">

هر `🔗` به سند مبنا لینک می‌دهد (معامله، تسویه، گواهی ری‌گیری).

---

## ۱.۶ اتصال WebSocket در وب

</div>

```typescript
// Composables/useWebSocket.ts

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

export function useWebSocket() {
  const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: 443,
    forceTLS: true,
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/api/v1/broadcasting/auth',
    auth: { headers: { Authorization: `Bearer ${token.value}` } },
  });

  const connectionState = ref<'connected' | 'connecting' | 'disconnected'>('connecting');

  echo.connector.pusher.connection.bind('state_change', (states: any) => {
    connectionState.value = states.current;

    if (states.current === 'connected') {
      // ⚠️ همگام‌سازی مجدد وضعیت پس از اتصال
      marketStore.refresh();
      balanceStore.refresh();
      orderStore.refresh();
    }
  });

  onUnmounted(() => echo.disconnect());

  return { echo, connectionState };
}
```

<div dir="rtl">

</div>

```typescript
// Composables/useOrderBook.ts

export function useOrderBook(instrument: Ref<string>) {
  const { echo } = useWebSocket();
  const bids = ref<DepthLevel[]>([]);
  const asks = ref<DepthLevel[]>([]);
  const lastUpdate = ref<Date | null>(null);

  // بارگیری اولیه از REST
  const load = async () => {
    const { data } = await axios.get(`/api/v1/market/depth/${instrument.value}`);
    bids.value = data.data.bids;
    asks.value = data.data.asks;
    lastUpdate.value = new Date(data.data.as_of);
  };

  watchEffect((onCleanup) => {
    load();

    const channel = echo.channel(`market.${instrument.value}`);

    channel.listen('.depth.updated', (e: DepthEvent) => {
      bids.value = e.bids.map(toDepthLevel);
      asks.value = e.asks.map(toDepthLevel);
      lastUpdate.value = new Date(e.timestamp);
    });

    onCleanup(() => echo.leave(`market.${instrument.value}`));
  });

  // تشخیص داده کهنه
  const isStale = computed(() => {
    if (!lastUpdate.value) return true;
    return Date.now() - lastUpdate.value.getTime() > 30_000;
  });

  return { bids, asks, lastUpdate, isStale, reload: load };
}
```

<div dir="rtl">

---

# بخش ب — پنل ادمین

## ۱.۷ چرا Filament؟

</div>

```
· تولید سریع CRUD برای ۸۰+ جدول
· جدول، فرم، فیلتر و اکشن آماده
· سیستم مجوز یکپارچه
· widget داشبورد
· ممیزی و لاگ داخلی

⚠️ اما: صفحات حساس (تسویه، دفتر، AML) باید سفارشی نوشته شوند،
   نه CRUD خودکار. CRUD خودکار روی داده مالی خطرناک است.
```

<div dir="rtl">

---

## ۱.۸ ساختار پنل ادمین

</div>

```
app/Filament/
├── Resources/                      ◄── CRUD استاندارد
│   ├── OrganizationResource.php
│   ├── UserResource.php
│   ├── LaboratoryResource.php
│   ├── RefinerResource.php
│   ├── VaultResource.php
│   ├── InstrumentResource.php
│   ├── FeeScheduleResource.php
│   └── AmlRuleResource.php
│
├── Pages/                          ◄── صفحات سفارشی
│   ├── KycQueue.php
│   ├── SettlementMonitor.php
│   ├── LedgerReconciliation.php
│   ├── AmlFlagQueue.php
│   ├── DisputeQueue.php
│   ├── VaultInventory.php
│   ├── ManualLedgerAdjustment.php  ◄── با تأیید دوگانه
│   ├── PriceControl.php
│   └── SystemSettings.php
│
├── Widgets/
│   ├── PlatformStatsOverview.php
│   ├── TradingVolumeChart.php
│   ├── SettlementHealthWidget.php
│   ├── OverdueSettlementsTable.php
│   ├── LedgerDiscrepancyAlert.php  ◄── بحرانی
│   ├── AmlFlagCounter.php
│   └── SystemHealthWidget.php
│
└── Actions/
    ├── ApproveKycAction.php
    ├── SuspendOrganizationAction.php
    ├── ReverseSettlementAction.php
    └── ResolveDisputeAction.php
```

<div dir="rtl">

---

## ۱.۹ داشبورد ادمین

</div>

```
┌──────────────────────────────────────────────────────────────────────────┐
│  داشبورد پلتفرم                                    ۱۴۰۴/۰۸/۰۵  ۱۴:۳۰    │
├──────────────────────────────────────────────────────────────────────────┤
│  🔴 هشدارهای بحرانی                                                       │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │  ⛔ هیچ مغایرت دفتری وجود ندارد                              ✅     │  │
│  │  ⚠️ ۲ تسویه سررسیدگذشته                              [مشاهده]      │  │
│  │  ⚠️ ۱ پرچم AML بحرانی                                 [مشاهده]      │  │
│  └────────────────────────────────────────────────────────────────────┘  │
├──────────────────────────────────────────────────────────────────────────┤
│  ┌────────────┐ ┌────────────┐ ┌────────────┐ ┌────────────┐            │
│  │ اعضای فعال │ │ حجم امروز  │ │ معاملات    │ │ درآمد      │            │
│  │   ۱۴۲      │ │ ۸۴.۲ کیلو  │ │   ۳۸۲      │ │ ۱.۲ میلیارد│            │
│  │ ▲ ۳ جدید   │ │ ▲ ۱۲٪      │ │ ▲ ۸٪       │ │ ▲ ۱۰٪      │            │
│  └────────────┘ └────────────┘ └────────────┘ └────────────┘            │
├──────────────────────────────────────────────────────────────────────────┤
│  ┌──────────────────────────────┐ ┌──────────────────────────────────┐  │
│  │  حجم معاملات ۳۰ روز اخیر     │ │  سلامت تسویه                     │  │
│  │                              │ │  ────────────────────────────    │  │
│  │      [نمودار میله‌ای]         │ │  به‌موقع        ۹۹.۲٪  ████████  │  │
│  │                              │ │  با تأخیر        ۰.۷٪  ░         │  │
│  │                              │ │  نکول            ۰.۱٪  ░         │  │
│  └──────────────────────────────┘ └──────────────────────────────────┘  │
├──────────────────────────────────────────────────────────────────────────┤
│  صف‌های کاری                                                              │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │  KYC در انتظار بررسی           ۷      [مشاهده]                     │  │
│  │  پرچم AML باز                  ۱۴     [مشاهده]                     │  │
│  │  اختلاف در انتظار میانجی        ۲      [مشاهده]                     │  │
│  │  عملیات خزانه در انتظار تأیید   ۳      [مشاهده]                     │  │
│  │  درخواست افزایش سقف             ۵      [مشاهده]                     │  │
│  └────────────────────────────────────────────────────────────────────┘  │
├──────────────────────────────────────────────────────────────────────────┤
│  سلامت سیستم                                                             │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │  API p95         ۸۲ms  🟢    صف settlement      ۳ 🟢               │  │
│  │  DB اتصال        ۲۴٪   🟢    صف notification   ۱۲ 🟢               │  │
│  │  Redis           🟢          failed_jobs        ۰ 🟢               │  │
│  │  WebSocket       ۱۴۲ اتصال   منبع قیمت          🟢 اصلی            │  │
│  │  آخرین reconcile ۰۲:۰۰ ✅    آخرین بکاپ        ۰۳:۰۰ ✅            │  │
│  └────────────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────┘
```

<div dir="rtl">

---

## ۱.۱۰ صفحه اصلاح دستی دفتر — با تأیید دوگانه

این حساس‌ترین صفحه سیستم است.

</div>

```php
namespace App\Filament\Pages;

class ManualLedgerAdjustment extends Page
{
    protected static ?string $navigationGroup = 'دفتر کل';
    protected static ?string $title = 'اصلاح دستی دفتر';

    public static function canAccess(): bool
    {
        return auth()->user()->hasRole('SETTLEMENT_OFFICER');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('اطلاعات اصلاح')->schema([
                Select::make('organization_id')
                    ->label('سازمان')
                    ->searchable()
                    ->required(),

                Select::make('asset_type')
                    ->options(['GOLD' => 'طلا', 'RIAL' => 'ریال'])
                    ->required()
                    ->live(),

                TextInput::make('amount')
                    ->label(fn ($get) => $get('asset_type') === 'GOLD'
                        ? 'مقدار (میلی‌گرم — منفی برای کاهش)'
                        : 'مبلغ (ریال — منفی برای کاهش)')
                    ->numeric()
                    ->required()
                    ->helperText('⚠️ عدد صحیح. منفی = کاهش موجودی'),

                Select::make('offset_account')
                    ->label('حساب طرف مقابل')
                    ->options([
                        'SUSPENSE'            => 'حساب معلق',
                        'ROUNDING_DIFFERENCE' => 'تفاوت گِردکردن',
                        'ASSAY_VARIANCE'      => 'اختلاف ری‌گیری',
                        'PROCESSING_LOSS'     => 'افت فرآوری',
                    ])
                    ->required()
                    ->helperText('برای حفظ بقای جرم الزامی است'),

                Textarea::make('reason')
                    ->label('دلیل (اجباری و مفصل)')
                    ->required()
                    ->minLength(50)
                    ->rows(4),

                FileUpload::make('supporting_document')
                    ->label('مستند پشتیبان')
                    ->required(),
            ]),
        ]);
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        // ایجاد درخواست — هنوز اجرا نمی‌شود
        $request = LedgerAdjustmentRequest::create([
            ...$data,
            'requested_by_user_id' => auth()->id(),
            'status' => 'PENDING_APPROVAL',
        ]);

        // اعلان به PLATFORM_ADMIN
        Notification::make()
            ->title('درخواست اصلاح ثبت شد')
            ->body('منتظر تأیید مدیر پلتفرم است. بدون تأیید اجرا نمی‌شود.')
            ->warning()
            ->send();

        event(new LedgerAdjustmentRequested($request->id));
    }
}
```

<div dir="rtl">

**اجرا فقط پس از تأیید کاربر دوم با نقش `PLATFORM_ADMIN`، و
`requested_by_user_id != approved_by_user_id`.**

---

## ۱.۱۱ قواعد امنیتی پنل ادمین

</div>

```
[ ] 2FA اجباری برای همه کارکنان
[ ] محدودیت IP به شبکه دفتر (اختیاری اما توصیه‌شده)
[ ] جلسه کوتاه (۳۰ دقیقه بی‌کاری ► خروج)
[ ] هر مشاهده داده حساس در Audit ثبت شود
[ ] هر اقدام نیازمند یادداشت
[ ] دسترسی AML جدا از دسترسی عمومی ادمین
[ ] هیچ کارمندی نتواند سازمان خودش یا آشنایانش را بررسی کند
[ ] عملیات مالی همیشه تأیید دوگانه
[ ] بدون امکان حذف هیچ رکورد مالی
[ ] بدون امکان اجرای SQL خام از پنل
```

<div dir="rtl">

### ثبت مشاهده داده حساس

</div>

```php
// در Resource
protected function afterFill(): void
{
    AuditLog::record(
        action: 'admin.view_sensitive',
        subjectType: 'Organization',
        subjectId: $this->record->id,
        metadata: ['fields' => ['national_id', 'bank_accounts']],
    );
}
```

</div>
