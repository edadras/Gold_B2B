@extends('admin::layouts.app')
@section('title', 'صف پرچم‌های AML')
@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    <form method="GET" action="{{ route('admin.aml.index') }}" class="filters">
        <div class="field">
            <label for="severity">شدت</label>
            <select id="severity" name="severity">
                <option value="">همه</option>
                @foreach (['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'] as $severity)
                    <option value="{{ $severity }}" @selected($activeSeverity === $severity)>{{ $severity }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="status">وضعیت</label>
            <select id="status" name="status">
                <option value="">همه بازها</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected($activeStatus === $status)>{{ $status }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit">اعمال</button>
    </form>

    <div class="panel">
        <h2>پرچم‌ها</h2>
        <div class="body">
            <x-admin::data-table
                :columns="[
                    ['label' => 'شدت'],
                    ['label' => 'قانون'],
                    ['label' => 'سازمان'],
                    ['label' => 'خلاصه'],
                    ['label' => 'وضعیت'],
                    ['label' => 'زمان'],
                    ['label' => ''],
                ]"
                :empty="$flags === []"
                empty-message="پرچمی در این فیلتر نیست.">
                @foreach ($flags as $flag)
                    <tr>
                        <td><x-admin::status-badge :value="$flag->severity" :tone="Format::severityTone($flag->severity)" /></td>
                        <td class="mono">{{ $flag->ruleName ?? $flag->ruleCode }}</td>
                        <td>{{ $flag->organizationName ?? '#'.$flag->organizationId }}</td>
                        <td class="wrap">{{ $flag->summary }}</td>
                        <td><x-admin::status-badge :value="$flag->status" :tone="Format::statusTone($flag->status)" /></td>
                        <td class="mono">{{ $flag->raisedAt }}</td>
                        <td><a href="{{ route('admin.aml.show', $flag->id) }}">بررسی</a></td>
                    </tr>
                @endforeach
            </x-admin::data-table>
        </div>
    </div>
@endsection
