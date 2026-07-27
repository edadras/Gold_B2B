@extends('admin::layouts.app')
@section('title', 'تسویه '.$settlement->settlementCode)
@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    <div class="panel">
        <h2>مشخصات</h2>
        <div class="body">
            <dl class="kv">
                <dt>کد</dt><dd class="mono">{{ $settlement->settlementCode }}</dd>
                <dt>وضعیت</dt><dd><x-admin::status-badge :value="$settlement->status" :tone="Format::statusTone($settlement->status)" /></dd>
                <dt>نوع</dt><dd>{{ $settlement->settlementType }}</dd>
                <dt>معامله</dt><dd>{{ $settlement->tradeId ?? '—' }}</dd>
                <dt>تحویل‌دهنده طلا</dt><dd>{{ $settlement->goldDelivererName ?? '#'.$settlement->goldDelivererOrgId }}</dd>
                <dt>دریافت‌کننده طلا</dt><dd>{{ $settlement->goldReceiverName ?? '#'.$settlement->goldReceiverOrgId }}</dd>
                <dt>وزن خالص</dt><dd>{{ Format::grams($settlement->fineWeightMg) }} گرم</dd>
                <dt>مبلغ</dt><dd>{{ Format::rial($settlement->cashAmountRial) }} ریال</dd>
                <dt>سررسید</dt><dd class="mono">{{ $settlement->deadlineAt ?? '—' }}</dd>
                <dt>سررسیدگذشته از</dt><dd class="mono">{{ $settlement->overdueSince ?? '—' }}</dd>
                <dt>جریمه</dt><dd>{{ Format::rial($settlement->penaltyRial) }} ریال</dd>
                <dt>سطح تشدید</dt><dd>{{ $settlement->escalationLevel }}</dd>
                <dt>اختلاف</dt><dd>{{ $settlement->disputeId ?? '—' }}</dd>
            </dl>
            {{-- No action buttons. Moving a settlement's state machine belongs
                 to the Settlement module; a status written from the panel would
                 be one its own transitions could never have produced. --}}
            <p class="hint">تغییر وضعیت تسویه از این صفحه ممکن نیست؛ این کار از مسیر ماژول تسویه انجام می‌شود.</p>
        </div>
    </div>

    <div class="panel">
        <h2>خط زمانی رویدادها</h2>
        <div class="body">
            @if ($timeline === [])
                <x-admin::empty-state message="رویدادی ثبت نشده است." />
            @else
                <ol class="timeline">
                    @foreach ($timeline as $event)
                        <li>
                            <div class="when">{{ $event->occurredAt }} · {{ $event->actorType }}@if ($event->actorUserId !== null) #{{ $event->actorUserId }}@endif</div>
                            <div>
                                {{ $event->fromStatus ?? '—' }} ◄ {{ $event->toStatus }}
                                @if ($event->reason !== null) — {{ $event->reason }} @endif
                            </div>
                            @if ($event->transactionGroup !== null)
                                <div class="mono">گروه تراکنش: {{ $event->transactionGroup }}</div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>
@endsection
