@extends('admin::layouts.app')
@section('title', 'مدیریت سازمان‌ها')
@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    <form method="GET" action="{{ route('admin.organizations.index') }}" class="filters">
        <div class="field">
            <label for="q">جست‌وجو</label>
            <input id="q" name="q" type="text" value="{{ $search }}" placeholder="نام یا شماره ثبت">
        </div>
        <div class="field">
            <label for="status">وضعیت</label>
            <select id="status" name="status">
                <option value="">همه</option>
                @foreach (['PENDING', 'UNDER_REVIEW', 'INFO_REQUIRED', 'VERIFIED', 'ACTIVE', 'RESTRICTED', 'SUSPENDED', 'CLOSED', 'REJECTED'] as $status)
                    <option value="{{ $status }}" @selected($activeStatus === $status)>{{ $status }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit">اعمال</button>
    </form>

    <div class="panel">
        <h2>سازمان‌ها</h2>
        <div class="body">
            <x-admin::data-table
                :columns="[
                    ['label' => 'نام'],
                    ['label' => 'نوع'],
                    ['label' => 'وضعیت'],
                    ['label' => 'ریسک'],
                    ['label' => 'انطباق'],
                    ['label' => 'شهر'],
                    ['label' => ''],
                ]"
                :empty="$organizations === []">
                @foreach ($organizations as $organization)
                    <tr>
                        <td>{{ $organization->displayName }}</td>
                        <td>{{ $organization->type }}</td>
                        <td><x-admin::status-badge :value="$organization->status" :tone="Format::statusTone($organization->status)" /></td>
                        <td><x-admin::status-badge :value="$organization->riskLevel" :tone="Format::severityTone($organization->riskLevel)" /></td>
                        <td>{{ $organization->complianceState }}</td>
                        <td>{{ $organization->city ?? '—' }}</td>
                        <td><a href="{{ route('admin.organizations.show', $organization->id) }}">مشاهده</a></td>
                    </tr>
                @endforeach
            </x-admin::data-table>
        </div>
    </div>
@endsection
