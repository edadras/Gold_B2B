@extends('admin::layouts.app')
@section('title', 'لاگ ممیزی')
@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    <form method="GET" action="{{ route('admin.audit.index') }}" class="filters">
        <div class="field">
            <label for="actor_id">شناسه عامل</label>
            <input id="actor_id" name="actor_id" type="number" value="{{ $filters['actor_id'] }}">
        </div>
        <div class="field">
            <label for="subject_type">نوع موضوع</label>
            <input id="subject_type" name="subject_type" type="text" value="{{ $filters['subject_type'] }}" list="subject-types">
        </div>
        <div class="field">
            <label for="subject_id">شناسه موضوع</label>
            <input id="subject_id" name="subject_id" type="number" value="{{ $filters['subject_id'] }}">
        </div>
        <div class="field">
            <label for="action">اقدام</label>
            <input id="action" name="action" type="text" value="{{ $filters['action'] }}" list="actions" placeholder="مثال: admin.">
            <datalist id="actions">
                @foreach ($actions as $action)
                    <option value="{{ $action }}"></option>
                @endforeach
            </datalist>
        </div>
        <div class="field">
            <label for="organization_id">سازمان</label>
            <input id="organization_id" name="organization_id" type="number" value="{{ $filters['organization_id'] }}">
        </div>
        <div class="field">
            <label for="result">نتیجه</label>
            <select id="result" name="result">
                <option value="">همه</option>
                @foreach (['success', 'failure', 'denied'] as $result)
                    <option value="{{ $result }}" @selected($filters['result'] === $result)>{{ $result }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit">جست‌وجو</button>
    </form>

    <div class="panel">
        <h2>{{ Format::thousands($total) }} رکورد — صفحه {{ $page }}</h2>
        <div class="body">
            <x-admin::data-table
                :columns="[
                    ['label' => 'شناسه', 'numeric' => true],
                    ['label' => 'زمان'],
                    ['label' => 'عامل'],
                    ['label' => 'اقدام'],
                    ['label' => 'موضوع'],
                    ['label' => 'سازمان', 'numeric' => true],
                    ['label' => 'نتیجه'],
                ]"
                :empty="$rows === []">
                @foreach ($rows as $row)
                    <tr>
                        <td class="num">{{ $row->id }}</td>
                        <td class="mono">{{ $row->occurredAt }}</td>
                        <td>{{ $row->actorName ?? ($row->actorId === null ? $row->actorType : $row->actorType.' #'.$row->actorId) }}</td>
                        <td class="mono">{{ $row->action }}</td>
                        <td>{{ $row->subjectType ?? '—' }} {{ $row->subjectId ?? '' }}</td>
                        <td class="num">{{ $row->organizationId ?? '—' }}</td>
                        <td><x-admin::status-badge :value="$row->result" :tone="$row->result === 'success' ? 'ok' : ($row->result === 'denied' ? 'bad' : 'warn')" /></td>
                    </tr>
                @endforeach
            </x-admin::data-table>

            <div class="tabs" style="margin-block-start:16px">
                @if ($page > 1)
                    <a href="{{ route('admin.audit.index', array_merge(array_filter($filters, fn ($v) => $v !== null), ['page' => $page - 1])) }}">صفحه قبل</a>
                @endif
                @if ($page * $perPage < $total)
                    <a href="{{ route('admin.audit.index', array_merge(array_filter($filters, fn ($v) => $v !== null), ['page' => $page + 1])) }}">صفحه بعد</a>
                @endif
            </div>
        </div>
    </div>
@endsection
