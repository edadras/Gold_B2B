@extends('admin::layouts.app')
@section('title', 'درخواست اصلاح '.$adjustment->reference)
@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    <div class="panel">
        <h2>مشخصات درخواست</h2>
        <div class="body">
            <dl class="kv">
                <dt>شناسه</dt><dd class="mono">{{ $adjustment->reference }}</dd>
                <dt>وضعیت</dt><dd><x-admin::status-badge :value="$adjustment->status->label()" :tone="$adjustment->status->tone()" /></dd>
                <dt>سازمان</dt><dd class="num">{{ $adjustment->organization_id }}</dd>
                <dt>دارایی</dt><dd>{{ $adjustment->asset_type->label() }}</dd>
                <dt>مقدار</dt><dd class="num">{{ Format::signed($adjustment->amount) }} {{ $adjustment->asset_type->unitLabel() }}</dd>
                <dt>حساب طرف مقابل</dt><dd>{{ $adjustment->offset_account->label() }}</dd>
                <dt>ثبت‌کننده</dt><dd class="num">{{ $adjustment->requested_by_user_id }}</dd>
                <dt>زمان ثبت</dt><dd class="mono">{{ $adjustment->requested_at }}</dd>
                <dt>تأییدکننده</dt><dd class="num">{{ $adjustment->approved_by_user_id ?? '—' }}</dd>
                <dt>یادداشت تصمیم</dt><dd class="wrap">{{ $adjustment->decision_note ?? '—' }}</dd>
                <dt>گروه تراکنش</dt><dd class="mono">{{ $adjustment->transaction_group ?? '—' }}</dd>
                <dt>ثبت‌های دفتر</dt>
                <dd class="num">
                    {{ $adjustment->member_entry_id ?? '—' }} / {{ $adjustment->offset_entry_id ?? '—' }}
                </dd>
                <dt>مستند پشتیبان</dt><dd class="mono">{{ $adjustment->supporting_document_name ?? $adjustment->supporting_document_path }}</dd>
            </dl>
        </div>
    </div>

    <div class="panel">
        <h2>دلیل</h2>
        <div class="body"><p style="white-space:pre-wrap">{{ $adjustment->reason }}</p></div>
    </div>

    @if ($adjustment->isPending())
        <div class="panel">
            <h2>تصمیم مدیر پلتفرم</h2>
            <div class="body">
                <x-admin::confirm-dialog
                    summary="تأیید و ثبت در دفتر"
                    warning="با تأیید، دو ثبت متوازن در دفتر کل ایجاد می‌شود. ثبت دفتر هرگز حذف یا ویرایش نمی‌شود؛ اصلاح بعدی تنها با ثبت معکوس ممکن است.">
                    <form method="POST" action="{{ route('admin.ledger.adjustments.approve', $adjustment->id) }}">
                        @csrf
                        <div class="field">
                            <label for="decision_note">یادداشت تأیید (اجباری)</label>
                            <textarea id="decision_note" name="decision_note" minlength="10" required></textarea>
                        </div>
                        <button type="submit" class="danger">تأیید و ثبت در دفتر</button>
                    </form>
                </x-admin::confirm-dialog>

                <form method="POST" action="{{ route('admin.ledger.adjustments.reject', $adjustment->id) }}" style="margin-block-start:16px">
                    @csrf
                    <div class="field">
                        <label for="reject_note">یادداشت رد (اجباری)</label>
                        <textarea id="reject_note" name="decision_note" minlength="10" required></textarea>
                    </div>
                    <button type="submit">رد درخواست</button>
                </form>
            </div>
        </div>
    @endif
@endsection
