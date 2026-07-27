@extends('admin::layouts.app')
@section('title', 'اصلاح دستی دفتر')
@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    <div class="alert warn">
        <div>
            <div class="title">این حساس‌ترین صفحه سامانه است</div>
            <div>هر درخواست فقط پس از تأیید کاربر دومی با نقش متفاوت در دفتر ثبت می‌شود. هیچ درخواستی از این صفحه حذف نمی‌شود.</div>
        </div>
        <a class="btn primary" href="{{ route('admin.ledger.adjustments.create') }}">درخواست جدید</a>
    </div>

    <div class="panel">
        <h2>در انتظار تأیید</h2>
        <div class="body">
            <x-admin::data-table
                :columns="[
                    ['label' => 'شناسه'],
                    ['label' => 'سازمان', 'numeric' => true],
                    ['label' => 'دارایی'],
                    ['label' => 'مبلغ', 'numeric' => true],
                    ['label' => 'حساب طرف مقابل'],
                    ['label' => 'ثبت‌کننده', 'numeric' => true],
                    ['label' => 'زمان'],
                    ['label' => ''],
                ]"
                :empty="$pending->isEmpty()"
                empty-message="درخواستی در انتظار تأیید نیست.">
                @foreach ($pending as $item)
                    <tr>
                        <td class="mono">{{ $item->reference }}</td>
                        <td class="num">{{ $item->organization_id }}</td>
                        <td>{{ $item->asset_type->label() }}</td>
                        <td @class(['num', 'neg' => $item->amount < 0])>{{ Format::signed($item->amount) }}</td>
                        <td>{{ $item->offset_account->label() }}</td>
                        <td class="num">{{ $item->requested_by_user_id }}</td>
                        <td class="mono">{{ $item->requested_at }}</td>
                        <td><a href="{{ route('admin.ledger.adjustments.show', $item->id) }}">بررسی</a></td>
                    </tr>
                @endforeach
            </x-admin::data-table>
        </div>
    </div>

    <div class="panel">
        <h2>تصمیم‌گرفته‌شده</h2>
        <div class="body">
            <x-admin::data-table
                :columns="[
                    ['label' => 'شناسه'],
                    ['label' => 'وضعیت'],
                    ['label' => 'سازمان', 'numeric' => true],
                    ['label' => 'مبلغ', 'numeric' => true],
                    ['label' => 'ثبت‌کننده', 'numeric' => true],
                    ['label' => 'تأییدکننده', 'numeric' => true],
                    ['label' => 'گروه تراکنش'],
                    ['label' => ''],
                ]"
                :empty="$recent->isEmpty()">
                @foreach ($recent as $item)
                    <tr>
                        <td class="mono">{{ $item->reference }}</td>
                        <td><x-admin::status-badge :value="$item->status->label()" :tone="$item->status->tone()" /></td>
                        <td class="num">{{ $item->organization_id }}</td>
                        <td @class(['num', 'neg' => $item->amount < 0])>{{ Format::signed($item->amount) }}</td>
                        <td class="num">{{ $item->requested_by_user_id }}</td>
                        <td class="num">{{ $item->approved_by_user_id ?? '—' }}</td>
                        <td class="mono">{{ $item->transaction_group ?? '—' }}</td>
                        <td><a href="{{ route('admin.ledger.adjustments.show', $item->id) }}">جزئیات</a></td>
                    </tr>
                @endforeach
            </x-admin::data-table>
        </div>
    </div>
@endsection
