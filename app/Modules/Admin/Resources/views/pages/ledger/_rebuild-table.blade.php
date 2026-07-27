@php use App\Modules\Admin\Domain\Format; @endphp
<div class="panel">
    <h2>مقایسه مانده ذخیره‌شده با مانده بازسازی‌شده</h2>
    <div class="body">
        <x-admin::data-table
            :columns="[
                ['label' => 'حساب', 'numeric' => true],
                ['label' => 'دارایی'],
                ['label' => 'سطل'],
                ['label' => 'مانده ذخیره‌شده', 'numeric' => true],
                ['label' => 'مانده بازسازی‌شده', 'numeric' => true],
                ['label' => 'اختلاف', 'numeric' => true],
                ['label' => 'تعداد ثبت (ذخیره/بازسازی)'],
                ['label' => 'آخرین ثبت (ذخیره/بازسازی)'],
                ['label' => 'وضعیت'],
            ]"
            :empty="$rebuilds === []">
            @foreach ($rebuilds as $rebuild)
                <tr>
                    <td class="num"><a href="{{ route('admin.ledger.account', $rebuild->accountId) }}">{{ $rebuild->accountId }}</a></td>
                    <td>{{ $rebuild->assetType }}</td>
                    <td>{{ $rebuild->bucket }}</td>
                    <td class="num">{{ Format::thousands($rebuild->storedBalance) }}</td>
                    <td class="num">{{ Format::thousands($rebuild->rebuiltBalance) }}</td>
                    <td @class(['num', 'neg' => $rebuild->balanceDifference() !== 0])>{{ Format::signed($rebuild->balanceDifference()) }}</td>
                    <td class="num">{{ $rebuild->storedEntryCount }} / {{ $rebuild->rebuiltEntryCount }}</td>
                    <td class="num">{{ $rebuild->storedLastEntryId ?? '—' }} / {{ $rebuild->rebuiltLastEntryId ?? '—' }}</td>
                    <td><x-admin::status-badge :value="$rebuild->matches() ? 'هم‌خوان' : 'مغایر'" :tone="$rebuild->matches() ? 'ok' : 'bad'" /></td>
                </tr>
            @endforeach
        </x-admin::data-table>
    </div>
</div>
