@extends('admin::layouts.app')
@section('title', 'صف اختلافات')
@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    <div class="tabs">
        <a href="{{ route('admin.disputes.index') }}" class="{{ $activeStatus === '' ? 'active' : '' }}">همه بازها</a>
        @foreach ($statuses as $status)
            <a href="{{ route('admin.disputes.index', ['status' => $status]) }}"
               class="{{ $activeStatus === $status ? 'active' : '' }}">{{ $status }}</a>
        @endforeach
    </div>

    <div class="panel">
        <h2>پرونده‌ها</h2>
        <div class="body">
            <x-admin::data-table
                :columns="[
                    ['label' => 'شماره'],
                    ['label' => 'اولویت'],
                    ['label' => 'نوع'],
                    ['label' => 'وضعیت'],
                    ['label' => 'خواهان'],
                    ['label' => 'خوانده'],
                    ['label' => 'ادعا (گرم)', 'numeric' => true],
                    ['label' => 'ادعا (ریال)', 'numeric' => true],
                    ['label' => 'مهلت پاسخ'],
                    ['label' => ''],
                ]"
                :empty="$disputes === []">
                @foreach ($disputes as $dispute)
                    <tr>
                        <td class="mono">{{ $dispute->caseNumber }}</td>
                        <td><x-admin::status-badge :value="$dispute->priority" :tone="Format::severityTone($dispute->priority === 'URGENT' ? 'CRITICAL' : $dispute->priority)" /></td>
                        <td>{{ $dispute->disputeType }}</td>
                        <td><x-admin::status-badge :value="$dispute->status" :tone="Format::statusTone($dispute->status)" /></td>
                        <td>{{ $dispute->claimantName ?? '#'.$dispute->claimantOrgId }}</td>
                        <td>{{ $dispute->respondentName ?? '#'.$dispute->respondentOrgId }}</td>
                        <td class="num">{{ Format::grams($dispute->claimGoldMg) }}</td>
                        <td class="num">{{ Format::rial($dispute->claimRial) }}</td>
                        <td class="mono">{{ $dispute->replyDeadlineAt ?? '—' }}</td>
                        <td><a href="{{ route('admin.disputes.show', $dispute->id) }}">پرونده</a></td>
                    </tr>
                @endforeach
            </x-admin::data-table>
        </div>
    </div>
@endsection
