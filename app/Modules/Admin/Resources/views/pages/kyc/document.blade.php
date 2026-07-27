@extends('admin::layouts.app')
@section('title', 'مشاهده مدرک')
@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    <div class="panel">
        <h2>مدرک #{{ $document['id'] }}</h2>
        <div class="body">
            {{-- Reaching this page has already written an audit row: §1.11
                 requires every read of sensitive data to be recorded, and the
                 record is written before the page renders, not after. --}}
            <p class="hint">مشاهده این مدرک در لاگ ممیزی ثبت شد.</p>
            <dl class="kv">
                <dt>نوع</dt><dd>{{ $document['type'] }}</dd>
                <dt>وضعیت</dt><dd><x-admin::status-badge :value="$document['status']" :tone="Format::statusTone($document['status'])" /></dd>
                <dt>نام فایل</dt><dd>{{ $document['original_filename'] ?? '—' }}</dd>
                <dt>اندازه</dt><dd class="num">{{ $document['size_bytes'] === null ? '—' : Format::thousands($document['size_bytes']).' بایت' }}</dd>
                <dt>هش فایل</dt><dd class="mono">{{ $document['file_hash'] ?? '—' }}</dd>
            </dl>
            <p><a href="{{ route('admin.kyc.show', $organizationId) }}">بازگشت به پرونده</a></p>
        </div>
    </div>
@endsection
