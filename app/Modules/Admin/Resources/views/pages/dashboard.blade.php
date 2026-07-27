@extends('admin::layouts.app')
@section('title', 'داشبورد پلتفرم')

@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    {{--
        §1.9's ordering, and it is not cosmetic: critical alerts first, then the
        stat row, then the work queues, then system health. The ledger
        discrepancy alert is rendered before anything else and, when non-zero,
        gets the loudest treatment the stylesheet has — the books not adding up
        outranks every other fact on this page.
    --}}
    <div class="alerts">
        @foreach ($alerts as $alert)
            <div class="alert {{ $alert['severity'] }}">
                <div>
                    <div class="title">{{ $alert['title'] }}</div>
                    @if ($alert['key'] === 'ledger_discrepancy' && $alert['count'] > 0)
                        <div>مانده ذخیره‌شده با مجموع ثبت‌ها هم‌خوان نیست. تا رفع این مورد، هیچ عدد دیگری در این صفحه قابل اتکا نیست.</div>
                    @endif
                </div>
                <div style="display:flex;align-items:center;gap:12px">
                    @if ($alert['count'] > 0)
                        <span class="count">{{ Format::thousands($alert['count']) }}</span>
                    @endif
                    @if ($alert['href'] !== null)
                        <a class="btn" href="{{ route($alert['href']) }}">مشاهده</a>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid cols-4">
        <x-admin::stat-card
            label="اعضای فعال"
            :value="Format::thousands($stats->activeMembers)"
            :delta="$stats->newMembersToday > 0 ? '▲ '.$stats->newMembersToday.' جدید' : '—'"
            :tone="$stats->newMembersToday > 0 ? 'up' : 'flat'" />

        <x-admin::stat-card
            label="حجم امروز (کیلوگرم خالص)"
            :value="Format::kilograms($stats->volumeTodayFineMg)"
            :delta="Format::changeLabel($stats->volumeTodayFineMg, $stats->volumeYesterdayFineMg)"
            :tone="Format::changeTone($stats->volumeTodayFineMg, $stats->volumeYesterdayFineMg)" />

        <x-admin::stat-card
            label="معاملات امروز"
            :value="Format::thousands($stats->tradesToday)"
            :delta="Format::changeLabel($stats->tradesToday, $stats->tradesYesterday)"
            :tone="Format::changeTone($stats->tradesToday, $stats->tradesYesterday)" />

        <x-admin::stat-card
            label="کارمزد امروز (ریال)"
            :value="Format::rial($stats->feeIncomeTodayRial)"
            :delta="Format::changeLabel($stats->feeIncomeTodayRial, $stats->feeIncomeYesterdayRial)"
            :tone="Format::changeTone($stats->feeIncomeTodayRial, $stats->feeIncomeYesterdayRial)" />
    </div>

    <div class="grid cols-2">
        <div class="panel">
            <h2>صف‌های کاری</h2>
            <div class="body">
                <x-admin::data-table :columns="[['label' => 'صف'], ['label' => 'تعداد', 'numeric' => true], ['label' => '']]">
                    @foreach ([
                        ['KYC در انتظار بررسی', $queues->kycPending, 'admin.kyc.index'],
                        ['پرچم AML باز', $queues->amlOpen, 'admin.aml.index'],
                        ['پرچم AML بحرانی', $queues->amlCritical, 'admin.aml.index'],
                        ['اختلاف در انتظار میانجی', $queues->disputesAwaitingMediation, 'admin.disputes.index'],
                        ['عملیات خزانه در انتظار تأیید', $queues->custodyPendingApproval, 'admin.vaults.index'],
                        ['درخواست افزایش سقف', $queues->limitIncreaseRequests, null],
                        ['تسویه باز', $queues->settlementsOpen, 'admin.settlements.index'],
                        ['تسویه سررسیدگذشته', $queues->settlementsOverdue, 'admin.settlements.index'],
                        ['تسویه نکول‌شده', $queues->settlementsDefaulted, 'admin.settlements.index'],
                    ] as [$label, $count, $target])
                        <tr>
                            <td>{{ $label }}</td>
                            <td class="num">{{ Format::thousands($count) }}</td>
                            <td>@if ($target !== null)<a href="{{ route($target) }}">مشاهده</a>@endif</td>
                        </tr>
                    @endforeach
                </x-admin::data-table>
            </div>
        </div>

        <div class="panel">
            <h2>سلامت تسویه</h2>
            <div class="body">
                <dl class="kv">
                    <dt>به‌موقع</dt><dd>{{ Format::thousands($settlementHealth['on_time']) }}</dd>
                    <dt>با تأخیر</dt><dd>{{ Format::thousands($settlementHealth['late']) }}</dd>
                    <dt>نکول</dt><dd>{{ Format::thousands($settlementHealth['defaulted']) }}</dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>سلامت سیستم</h2>
        <div class="body">
            <x-admin::data-table :columns="[['label' => 'شاخص'], ['label' => 'مقدار'], ['label' => 'وضعیت']]">
                @foreach ($health as $indicator)
                    <tr>
                        <td>{{ $indicator->label }}</td>
                        <td class="mono">{{ $indicator->value }}</td>
                        <td><x-admin::status-badge :value="$indicator->tone" :tone="$indicator->tone" /></td>
                    </tr>
                @endforeach
            </x-admin::data-table>
        </div>
    </div>
@endsection
