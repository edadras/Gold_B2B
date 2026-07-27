@extends('admin::layouts.app')
@section('title', 'موجودی خزانه #'.$vaultId)
@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    <div class="panel">
        <h2>تطبیق فیزیکی</h2>
        <div class="body">
            <dl class="kv">
                <dt>ظرفیت اعلامی</dt><dd class="num">{{ Format::grams($reconciliation['declared']) }} گرم</dd>
                <dt>مجموع شمش‌ها</dt><dd class="num">{{ Format::grams($reconciliation['lots']) }} گرم</dd>
                <dt>اختلاف</dt><dd class="num">{{ Format::signed($reconciliation['difference']) }} میلی‌گرم</dd>
            </dl>
            <p class="hint">
                اختلاف مثبت یعنی موجودی از ظرفیت اعلامی فراتر رفته است؛ این عدد جایگزین شمارش فیزیکی نیست.
            </p>
        </div>
    </div>

    <div class="panel">
        <h2>شمش‌ها</h2>
        <div class="body">
            <x-admin::data-table
                :columns="[
                    ['label' => 'کد شمش'],
                    ['label' => 'مالک'],
                    ['label' => 'وضعیت'],
                    ['label' => 'وزن ناخالص (گرم)', 'numeric' => true],
                    ['label' => 'عیار', 'numeric' => true],
                    ['label' => 'وزن خالص (گرم)', 'numeric' => true],
                    ['label' => 'محل'],
                ]"
                :empty="$lots === []">
                @foreach ($lots as $lot)
                    <tr>
                        <td class="mono">{{ $lot->lotCode }}</td>
                        <td>{{ $lot->ownerName ?? ($lot->ownerOrganizationId === null ? '—' : '#'.$lot->ownerOrganizationId) }}</td>
                        <td><x-admin::status-badge :value="$lot->status" :tone="Format::statusTone($lot->status)" /></td>
                        <td class="num">{{ Format::grams($lot->grossWeightMg) }}</td>
                        <td class="num">{{ $lot->purityX10 }}</td>
                        <td class="num">{{ Format::grams($lot->fineWeightMg) }}</td>
                        <td>{{ $lot->physicalLocation ?? ($lot->vaultBoxId === null ? '—' : 'صندوق #'.$lot->vaultBoxId) }}</td>
                    </tr>
                @endforeach
            </x-admin::data-table>
        </div>
    </div>
@endsection
