@extends('admin::layouts.app')
@section('title', 'پرونده اختلاف '.$context->dispute->caseNumber)
@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    <div class="panel">
        <h2>خلاصه پرونده</h2>
        <div class="body">
            <dl class="kv">
                <dt>شماره</dt><dd class="mono">{{ $context->dispute->caseNumber }}</dd>
                <dt>نوع</dt><dd>{{ $context->dispute->disputeType }}</dd>
                <dt>وضعیت</dt><dd><x-admin::status-badge :value="$context->dispute->status" :tone="Format::statusTone($context->dispute->status)" /></dd>
                <dt>خواهان</dt><dd>{{ $context->dispute->claimantName ?? '#'.$context->dispute->claimantOrgId }}</dd>
                <dt>خوانده</dt><dd>{{ $context->dispute->respondentName ?? '#'.$context->dispute->respondentOrgId }}</dd>
                <dt>ادعا</dt><dd>{{ Format::grams($context->dispute->claimGoldMg) }} گرم / {{ Format::rial($context->dispute->claimRial) }} ریال</dd>
                <dt>معامله / تسویه</dt><dd class="num">{{ $context->dispute->tradeId ?? '—' }} / {{ $context->dispute->settlementId ?? '—' }}</dd>
                <dt>میانجی</dt><dd class="num">{{ $context->dispute->mediatorUserId ?? '—' }}</dd>
                <dt>وجه مسدود آزاد شده؟</dt><dd>{{ $context->holdReleased ? 'بله' : 'خیر' }}</dd>
                <dt>ثبت‌های مسدودی</dt><dd class="num">{{ $context->holdGoldEntryId ?? '—' }} / {{ $context->holdRialEntryId ?? '—' }}</dd>
            </dl>
        </div>
    </div>

    <div class="grid cols-2">
        <div class="panel">
            <h2>خط زمانی</h2>
            <div class="body">
                @if ($context->timeline === [])
                    <x-admin::empty-state message="رویدادی ثبت نشده است." />
                @else
                    <ol class="timeline">
                        @foreach ($context->timeline as $event)
                            <li>
                                <div class="when">{{ $event['occurred_at'] }} · {{ $event['actor_type'] }}</div>
                                <div>{{ $event['action'] }} — {{ $event['from_status'] ?? '—' }} ◄ {{ $event['to_status'] ?? '—' }}</div>
                                @if ($event['message'] !== null)<div>{{ $event['message'] }}</div>@endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        </div>

        <div class="panel">
            <h2>مستندات</h2>
            <div class="body">
                <x-admin::data-table
                    :columns="[['label' => 'نوع'], ['label' => 'ارائه‌دهنده', 'numeric' => true], ['label' => 'زمان']]"
                    :empty="$context->evidence === []">
                    @foreach ($context->evidence as $item)
                        <tr>
                            <td>{{ $item['evidence_type'] ?? '—' }}</td>
                            <td class="num">{{ $item['submitted_by_org_id'] ?? '—' }}</td>
                            <td class="mono">{{ $item['created_at'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </x-admin::data-table>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>تعیین میانجی</h2>
        <div class="body">
            <form method="POST" action="{{ route('admin.disputes.mediator', $context->dispute->id) }}">
                @csrf
                <div class="field">
                    <label for="mediator_user_id">شناسه کاربر میانجی</label>
                    <input id="mediator_user_id" name="mediator_user_id" type="number" min="1" required>
                </div>
                <div class="field">
                    <label for="note">یادداشت (اجباری)</label>
                    <textarea id="note" name="note" minlength="10" required></textarea>
                </div>
                <button type="submit" class="primary">تعیین میانجی</button>
            </form>
            <p class="hint">صدور رأی و جابه‌جایی دارایی از این صفحه انجام نمی‌شود؛ آن مسیر در ماژول اختلافات است.</p>
        </div>
    </div>
@endsection
