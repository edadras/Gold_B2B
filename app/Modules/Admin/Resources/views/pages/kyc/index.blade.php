@extends('admin::layouts.app')
@section('title', 'صف بررسی KYC')
@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    <div class="tabs">
        <a href="{{ route('admin.kyc.index') }}" class="{{ $activeStatus === '' ? 'active' : '' }}">همه در جریان</a>
        @foreach ($statuses as $status)
            <a href="{{ route('admin.kyc.index', ['status' => $status]) }}"
               class="{{ $activeStatus === $status ? 'active' : '' }}">{{ $status }}</a>
        @endforeach
    </div>

    <div class="panel">
        <h2>پرونده‌های در انتظار بررسی</h2>
        <div class="body">
            <x-admin::data-table
                :columns="[
                    ['label' => 'سازمان'],
                    ['label' => 'وضعیت پرونده'],
                    ['label' => 'وضعیت عضو'],
                    ['label' => 'ریسک'],
                    ['label' => 'مدارک', 'numeric' => true],
                    ['label' => 'نوبت ارسال', 'numeric' => true],
                    ['label' => 'زمان ارسال'],
                    ['label' => ''],
                ]"
                :empty="$items === []"
                empty-message="پرونده‌ای در صف نیست.">
                @foreach ($items as $item)
                    <tr>
                        <td>{{ $item->organizationName }}</td>
                        <td><x-admin::status-badge :value="$item->status" :tone="Format::statusTone($item->status)" /></td>
                        <td><x-admin::status-badge :value="$item->organizationStatus" :tone="Format::statusTone($item->organizationStatus)" /></td>
                        <td><x-admin::status-badge :value="$item->riskLevel" :tone="Format::severityTone($item->riskLevel)" /></td>
                        <td class="num">{{ $item->documentCount }}</td>
                        <td class="num">{{ $item->submissionCount }}</td>
                        <td class="mono">{{ $item->submittedAt ?? '—' }}</td>
                        <td><a href="{{ route('admin.kyc.show', $item->organizationId) }}">بررسی</a></td>
                    </tr>
                @endforeach
            </x-admin::data-table>
        </div>
    </div>
@endsection
