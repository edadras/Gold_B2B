@extends('admin::layouts.app')
@section('title', 'تنظیمات سامانه')

@section('content')
    <p class="hint">
        تنها کلیدهای فهرست‌شده در این صفحه قابل ویرایش‌اند. هر تغییر با مقدار پیشین و پسین در لاگ ممیزی ثبت می‌شود.
    </p>

    @foreach ($groups as $group => $items)
        <div class="panel">
            <h2>{{ ['market' => 'بازار', 'fees' => 'کارمزد', 'settlement' => 'تسویه', 'limits' => 'سقف‌ها', 'pricing' => 'قیمت‌گذاری', 'dispute' => 'اختلاف'][$group] ?? $group }}</h2>
            <div class="body">
                @foreach ($items as $item)
                    <form method="POST" action="{{ route('admin.settings.update') }}" class="filters">
                        @csrf
                        <input type="hidden" name="key" value="{{ $item['key'] }}">
                        <div class="field" style="min-width:260px">
                            <label for="value-{{ $loop->index }}-{{ $group }}">{{ $item['label'] }}</label>
                            <input id="value-{{ $loop->index }}-{{ $group }}" name="value" type="text" value="{{ $item['value'] }}" required>
                            <div class="hint mono">{{ $item['key'] }}@if ($item['description']) — {{ $item['description'] }}@endif</div>
                        </div>
                        <div class="field" style="min-width:260px">
                            <label for="note-{{ $loop->index }}-{{ $group }}">یادداشت تغییر (اجباری)</label>
                            <input id="note-{{ $loop->index }}-{{ $group }}" name="note" type="text" minlength="10" required>
                        </div>
                        <button type="submit">ذخیره</button>
                    </form>
                @endforeach
            </div>
        </div>
    @endforeach
@endsection
