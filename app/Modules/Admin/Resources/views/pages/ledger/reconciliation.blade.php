@extends('admin::layouts.app')
@section('title', 'تطبیق دفتر کل')
@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    <div class="alerts">
        <div class="alert {{ $report->healthy() ? 'ok' : 'critical' }}">
            <div>
                <div class="title">{{ $report->healthy() ? 'دفتر تراز است' : 'دفتر تراز نیست' }}</div>
                <div>
                    {{ Format::thousands($report->accountsChecked) }} حساب بررسی شد ·
                    آخرین اجرای اسنپ‌شات: {{ $lastRunAt ?? 'هرگز' }}
                </div>
            </div>
            @if (! $report->healthy())
                <span class="count">{{ Format::thousands(count($report->discrepancies)) }}</span>
            @endif
        </div>
    </div>

    <div class="panel">
        <h2>مغایرت مانده (I2)</h2>
        <div class="body">
            <x-admin::data-table
                :columns="[
                    ['label' => 'حساب', 'numeric' => true],
                    ['label' => 'سازمان', 'numeric' => true],
                    ['label' => 'دارایی'],
                    ['label' => 'سطل'],
                    ['label' => 'مانده ذخیره‌شده', 'numeric' => true],
                    ['label' => 'مانده محاسبه‌شده', 'numeric' => true],
                    ['label' => 'اختلاف', 'numeric' => true],
                    ['label' => ''],
                ]"
                :empty="$report->discrepancies === []"
                empty-message="هیچ مغایرتی وجود ندارد.">
                @foreach ($report->discrepancies as $discrepancy)
                    <tr>
                        <td class="num">{{ $discrepancy->accountId }}</td>
                        <td class="num">{{ $discrepancy->organizationId }}</td>
                        <td>{{ $discrepancy->assetType }}</td>
                        <td>{{ $discrepancy->bucket }}</td>
                        <td class="num">{{ Format::thousands($discrepancy->storedBalance) }}</td>
                        <td class="num">{{ Format::thousands($discrepancy->computedBalance) }}</td>
                        <td class="num neg">{{ Format::signed($discrepancy->difference) }}</td>
                        <td><a href="{{ route('admin.ledger.account', $discrepancy->accountId) }}">بازسازی</a></td>
                    </tr>
                @endforeach
            </x-admin::data-table>
        </div>
    </div>

    <div class="grid cols-2">
        <div class="panel">
            <h2>مانده منفی غیرمجاز (I3)</h2>
            <div class="body">
                <x-admin::data-table
                    :columns="[['label' => 'حساب', 'numeric' => true], ['label' => 'سازمان', 'numeric' => true], ['label' => 'سطل'], ['label' => 'مانده', 'numeric' => true]]"
                    :empty="$report->negativeBalances === []"
                    empty-message="موردی یافت نشد.">
                    @foreach ($report->negativeBalances as $row)
                        <tr>
                            <td class="num">{{ $row['account_id'] }}</td>
                            <td class="num">{{ $row['organization_id'] }}</td>
                            <td>{{ $row['bucket'] }}</td>
                            <td class="num neg">{{ Format::thousands($row['balance']) }}</td>
                        </tr>
                    @endforeach
                </x-admin::data-table>
            </div>
        </div>

        <div class="panel">
            <h2>گروه‌های نامتوازن (I1)</h2>
            <div class="body">
                <x-admin::data-table
                    :columns="[['label' => 'گروه تراکنش'], ['label' => 'دارایی'], ['label' => 'مجموع', 'numeric' => true]]"
                    :empty="$report->unbalancedGroups === []"
                    empty-message="موردی یافت نشد.">
                    @foreach ($report->unbalancedGroups as $row)
                        <tr>
                            <td class="mono">{{ $row['transaction_group'] }}</td>
                            <td>{{ $row['asset_type'] }}</td>
                            <td class="num neg">{{ Format::signed($row['total']) }}</td>
                        </tr>
                    @endforeach
                </x-admin::data-table>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>بقای جرم در کل سیستم (I4)</h2>
        <div class="body">
            <x-admin::data-table
                :columns="[['label' => 'دارایی'], ['label' => 'مجموع همه ثبت‌ها', 'numeric' => true], ['label' => 'وضعیت']]"
                :empty="$report->conservation === []">
                @foreach ($report->conservation as $asset => $total)
                    <tr>
                        <td>{{ $asset }}</td>
                        <td class="num">{{ Format::signed($total) }}</td>
                        <td><x-admin::status-badge :value="$total === 0 ? 'تراز' : 'نامتوازن'" :tone="$total === 0 ? 'ok' : 'bad'" /></td>
                    </tr>
                @endforeach
            </x-admin::data-table>
        </div>
    </div>

    <div class="panel">
        <h2>بازسازی حساب‌های یک سازمان</h2>
        <div class="body">
            <form method="GET" action="{{ route('admin.ledger.reconciliation.organization') }}" class="filters">
                <div class="field">
                    <label for="organization_id">شناسه سازمان</label>
                    <input id="organization_id" name="organization_id" type="number" min="0" required>
                </div>
                <button type="submit">بازسازی</button>
            </form>
        </div>
    </div>
@endsection
