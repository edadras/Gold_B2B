@extends('admin::layouts.app')
@section('title', 'بازسازی حساب #'.$rebuild->accountId)
@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    @include('admin::pages.ledger._rebuild-table', ['rebuilds' => [$rebuild]])

    <div class="panel">
        <h2>مشخصات حساب</h2>
        <div class="body">
            <dl class="kv">
                <dt>حساب</dt><dd class="num">{{ $rebuild->accountId }}</dd>
                <dt>سازمان</dt><dd>{{ $rebuild->organizationName ?? '#'.$rebuild->organizationId }}</dd>
                <dt>دارایی / سطل</dt><dd>{{ $rebuild->assetType }} / {{ $rebuild->bucket }}</dd>
                <dt>حساب سیستمی</dt><dd>{{ $rebuild->systemAccountCode ?? '—' }}</dd>
            </dl>
            <p class="hint">
                این صفحه فقط گزارش می‌دهد. اصلاح مانده تنها از مسیر «اصلاح دستی دفتر» و با تأیید دوگانه ممکن است.
            </p>
        </div>
    </div>
@endsection
