@extends('admin::layouts.app')
@section('title', 'بررسی KYC — '.$dossier->organizationName)
@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    <div class="panel">
        <h2>خلاصه پرونده</h2>
        <div class="body">
            <dl class="kv">
                <dt>سازمان</dt><dd>{{ $dossier->organizationName }} (#{{ $dossier->organizationId }})</dd>
                <dt>نوع</dt><dd>{{ $dossier->organizationType }}</dd>
                <dt>وضعیت پرونده</dt><dd><x-admin::status-badge :value="$dossier->status" :tone="Format::statusTone($dossier->status)" /></dd>
                <dt>وضعیت عضو</dt><dd><x-admin::status-badge :value="$dossier->organizationStatus" :tone="Format::statusTone($dossier->organizationStatus)" /></dd>
                <dt>سطح ریسک</dt><dd><x-admin::status-badge :value="$dossier->riskLevel" :tone="Format::severityTone($dossier->riskLevel)" /></dd>
                <dt>زمان ارسال</dt><dd class="mono">{{ $dossier->submittedAt ?? '—' }}</dd>
                <dt>آخرین یادداشت</dt><dd>{{ $dossier->lastDecisionNote ?? '—' }}</dd>
            </dl>
        </div>
    </div>

    {{-- The automated-check panel. Identity computes the identifier verdicts;
         this screen only displays them, and a check it cannot evaluate says so
         instead of showing a reassuring green. --}}
    <div class="panel">
        <h2>بررسی‌های خودکار</h2>
        <div class="body">
            <x-admin::data-table
                :columns="[['label' => 'بررسی'], ['label' => 'نتیجه'], ['label' => 'توضیح']]"
                :empty="$dossier->automatedChecks === []">
                @foreach ($dossier->automatedChecks as $check)
                    <tr>
                        <td>{{ $check->label }}</td>
                        <td><x-admin::status-badge :value="$check->passed === null ? 'نامشخص' : ($check->passed ? 'تأیید' : 'رد')" :tone="$check->tone()" /></td>
                        <td class="wrap">{{ $check->detail }}</td>
                    </tr>
                @endforeach
            </x-admin::data-table>
        </div>
    </div>

    <div class="grid cols-2">
        <div class="panel">
            <h2>مدارک</h2>
            <div class="body">
                <x-admin::data-table
                    :columns="[['label' => 'نوع'], ['label' => 'وضعیت'], ['label' => 'نام فایل'], ['label' => '']]"
                    :empty="$dossier->documents === []">
                    @foreach ($dossier->documents as $document)
                        <tr>
                            <td>{{ $document['type'] }}</td>
                            <td><x-admin::status-badge :value="$document['status']" :tone="Format::statusTone($document['status'])" /></td>
                            <td class="wrap">{{ $document['original_filename'] ?? '—' }}</td>
                            <td><a href="{{ route('admin.kyc.document', [$dossier->organizationId, $document['id']]) }}">مشاهده</a></td>
                        </tr>
                    @endforeach
                </x-admin::data-table>
            </div>
        </div>

        <div class="panel">
            <h2>حساب‌های بانکی</h2>
            <div class="body">
                <x-admin::data-table
                    :columns="[['label' => 'بانک'], ['label' => 'صاحب حساب'], ['label' => 'وضعیت']]"
                    :empty="$dossier->bankAccounts === []">
                    @foreach ($dossier->bankAccounts as $account)
                        <tr>
                            <td>{{ $account['bank_name'] ?? '—' }}</td>
                            <td>{{ $account['account_holder_name'] ?? '—' }}</td>
                            <td><x-admin::status-badge :value="$account['status']" :tone="Format::statusTone($account['status'])" /></td>
                        </tr>
                    @endforeach
                </x-admin::data-table>
                <p class="hint">شماره شبا در این صفحه نمایش داده نمی‌شود؛ برای بررسی انطباق لازم نیست.</p>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>ثبت تصمیم</h2>
        <div class="body">
            <form method="POST" action="{{ route('admin.kyc.decide', $dossier->organizationId) }}">
                @csrf
                <div class="field">
                    <label for="decision">تصمیم</label>
                    <select id="decision" name="decision" required>
                        <option value="APPROVED">تأیید</option>
                        <option value="INFO_REQUIRED">نیاز به اطلاعات بیشتر</option>
                        <option value="REJECTED">رد</option>
                    </select>
                </div>
                <div class="field">
                    <label for="notes">یادداشت (اجباری)</label>
                    <textarea id="notes" name="notes" required minlength="10">{{ old('notes') }}</textarea>
                    <div class="hint">تصمیم بدون یادداشت مکتوب ثبت نمی‌شود.</div>
                </div>
                <button type="submit" class="primary">ثبت تصمیم</button>
            </form>
        </div>
    </div>

    <div class="panel">
        <h2>سابقه بررسی</h2>
        <div class="body">
            <x-admin::data-table
                :columns="[['label' => 'تصمیم'], ['label' => 'بررسی‌کننده', 'numeric' => true], ['label' => 'زمان'], ['label' => 'یادداشت']]"
                :empty="$dossier->reviewHistory === []">
                @foreach ($dossier->reviewHistory as $review)
                    <tr>
                        <td><x-admin::status-badge :value="$review['decision']" :tone="Format::statusTone($review['decision'])" /></td>
                        <td class="num">{{ $review['reviewer_user_id'] ?? '—' }}</td>
                        <td class="mono">{{ $review['reviewed_at'] ?? '—' }}</td>
                        <td class="wrap">{{ $review['notes'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </x-admin::data-table>
        </div>
    </div>
@endsection
