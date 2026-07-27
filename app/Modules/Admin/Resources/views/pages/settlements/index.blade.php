@extends('admin::layouts.app')
@section('title', 'پایش تسویه')
@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    <div class="tabs">
        @foreach ($tabs as $name)
            <a href="{{ route('admin.settlements.index', ['tab' => $name]) }}"
               class="{{ $tab === $name ? 'active' : '' }}">
                {{ ['open' => 'باز', 'overdue' => 'سررسیدگذشته', 'defaulted' => 'نکول', 'disputed' => 'در اختلاف'][$name] ?? $name }}
                ({{ Format::thousands($counts[$name] ?? 0) }})
            </a>
        @endforeach
    </div>

    <div class="panel">
        <h2>سلامت تسویه</h2>
        <div class="body">
            <dl class="kv">
                <dt>به‌موقع</dt><dd>{{ Format::thousands($health['on_time']) }}</dd>
                <dt>با تأخیر</dt><dd>{{ Format::thousands($health['late']) }}</dd>
                <dt>نکول</dt><dd>{{ Format::thousands($health['defaulted']) }}</dd>
            </dl>
        </div>
    </div>

    <div class="panel">
        <h2>تسویه‌ها</h2>
        <div class="body">
            <x-admin::data-table
                :columns="[
                    ['label' => 'کد'],
                    ['label' => 'وضعیت'],
                    ['label' => 'تحویل‌دهنده طلا'],
                    ['label' => 'دریافت‌کننده'],
                    ['label' => 'وزن خالص (گرم)', 'numeric' => true],
                    ['label' => 'مبلغ (ریال)', 'numeric' => true],
                    ['label' => 'سررسید'],
                    ['label' => 'سررسیدگذشته از'],
                    ['label' => 'جریمه (ریال)', 'numeric' => true],
                    ['label' => ''],
                ]"
                :empty="$rows === []">
                @foreach ($rows as $row)
                    <tr>
                        <td class="mono">{{ $row->settlementCode }}</td>
                        <td><x-admin::status-badge :value="$row->status" :tone="Format::statusTone($row->status)" /></td>
                        <td>{{ $row->goldDelivererName ?? '#'.$row->goldDelivererOrgId }}</td>
                        <td>{{ $row->goldReceiverName ?? '#'.$row->goldReceiverOrgId }}</td>
                        <td class="num">{{ Format::grams($row->fineWeightMg) }}</td>
                        <td class="num">{{ Format::rial($row->cashAmountRial) }}</td>
                        <td class="mono">{{ $row->deadlineAt ?? '—' }}</td>
                        <td class="mono">{{ $row->overdueSince ?? '—' }}</td>
                        <td class="num">{{ Format::rial($row->penaltyRial) }}</td>
                        <td><a href="{{ route('admin.settlements.show', $row->id) }}">جزئیات</a></td>
                    </tr>
                @endforeach
            </x-admin::data-table>
        </div>
    </div>
@endsection
