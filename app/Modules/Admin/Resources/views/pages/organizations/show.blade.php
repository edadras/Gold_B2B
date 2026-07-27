@extends('admin::layouts.app')
@section('title', $organization->displayName)
@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    <div class="panel">
        <h2>مشخصات عضو</h2>
        <div class="body">
            <dl class="kv">
                <dt>نام</dt><dd>{{ $organization->displayName }}</dd>
                <dt>شناسه</dt><dd class="num">{{ $organization->id }}</dd>
                <dt>نوع</dt><dd>{{ $organization->type }}</dd>
                <dt>وضعیت</dt><dd><x-admin::status-badge :value="$organization->status" :tone="Format::statusTone($organization->status)" /></dd>
                <dt>سطح ریسک</dt><dd><x-admin::status-badge :value="$organization->riskLevel" :tone="Format::severityTone($organization->riskLevel)" /></dd>
                <dt>وضعیت انطباق</dt><dd>{{ $organization->complianceState }}</dd>
                <dt>شهر</dt><dd>{{ $organization->city ?? '—' }}</dd>
                <dt>دلیل محدودیت فعلی</dt><dd class="wrap">{{ $organization->restrictionReason ?? '—' }}</dd>
                <dt>تاریخ ثبت</dt><dd class="mono">{{ $organization->createdAt ?? '—' }}</dd>
                @foreach ($balances as $asset => $balance)
                    <dt>مانده {{ $asset }}</dt><dd class="num">{{ Format::thousands($balance) }}</dd>
                @endforeach
            </dl>
        </div>
    </div>

    <div class="panel">
        <h2>تعلیق / محدودسازی</h2>
        <div class="body">
            <x-admin::confirm-dialog
                summary="تغییر وضعیت عضو"
                warning="تعلیق یا محدودسازی، سفارش‌های باز عضو را لغو و امکان معامله را قطع می‌کند. این اقدام در لاگ ممیزی با نام شما ثبت می‌شود.">
                <form method="POST" action="{{ route('admin.organizations.status', $organization->id) }}">
                    @csrf
                    <div class="field">
                        <label for="status">وضعیت جدید</label>
                        <select id="status" name="status" required>
                            @foreach ($transitions as $transition)
                                <option value="{{ $transition }}">{{ $transition }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="reason">دلیل (اجباری)</label>
                        <textarea id="reason" name="reason" minlength="15" required></textarea>
                    </div>
                    <button type="submit" class="danger">اعمال تغییر وضعیت</button>
                </form>
            </x-admin::confirm-dialog>
            <p class="hint">حذف سازمان از این پنل ممکن نیست؛ رکورد مالی هرگز حذف نمی‌شود.</p>
        </div>
    </div>
@endsection
