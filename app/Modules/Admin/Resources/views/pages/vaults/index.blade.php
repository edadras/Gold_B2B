@extends('admin::layouts.app')
@section('title', 'موجودی خزانه')
@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    <div class="panel">
        <h2>خزانه‌ها</h2>
        <div class="body">
            <x-admin::data-table
                :columns="[
                    ['label' => 'کد'],
                    ['label' => 'نام'],
                    ['label' => 'وضعیت'],
                    ['label' => 'ظرفیت (گرم)', 'numeric' => true],
                    ['label' => 'موجودی (گرم)', 'numeric' => true],
                    ['label' => 'تعداد شمش', 'numeric' => true],
                    ['label' => 'انقضای بیمه'],
                    ['label' => ''],
                ]"
                :empty="$vaults === []">
                @foreach ($vaults as $vault)
                    <tr>
                        <td class="mono">{{ $vault->vaultCode }}</td>
                        <td>{{ $vault->name }}</td>
                        <td><x-admin::status-badge :value="$vault->status" :tone="Format::statusTone($vault->status)" /></td>
                        <td class="num">{{ $vault->capacityFineMg === null ? '—' : Format::grams($vault->capacityFineMg) }}</td>
                        <td class="num">{{ Format::grams($vault->storedFineMg) }}</td>
                        <td class="num">{{ Format::thousands($vault->lotCount) }}</td>
                        <td class="mono">{{ $vault->coverageExpiresAt ?? '—' }}</td>
                        <td><a href="{{ route('admin.vaults.show', $vault->id) }}">موجودی</a></td>
                    </tr>
                @endforeach
            </x-admin::data-table>
        </div>
    </div>
@endsection
