@extends('admin::layouts.app')
@section('title', 'بازسازی حساب‌های سازمان')

@section('content')
    @if ($organizationId <= 0)
        <x-admin::empty-state message="شناسه سازمان را وارد کنید." />
    @else
        @include('admin::pages.ledger._rebuild-table', ['rebuilds' => $rebuilds])
    @endif
@endsection
