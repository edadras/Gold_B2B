{{--
    The standard data table (docs/08-frontend-web/01-web-panels.md §1.4 —
    numeric columns end-aligned and tabular, headers muted, empty state
    explicit rather than a blank rectangle).

    Usage:
        <x-admin::data-table :columns="[['label' => 'کد'], ['label' => 'مبلغ', 'numeric' => true]]" :empty="$rows === []">
            @foreach ($rows as $row) <tr>…</tr> @endforeach
        </x-admin::data-table>

    Row markup stays with the caller: the first screen that needed a link, a
    badge and a two-line cell would otherwise need three escape hatches here.
--}}
@props([
    'columns' => [],
    'empty' => false,
    'emptyMessage' => 'رکوردی برای نمایش نیست.',
])
<div class="table-wrap">
    <table class="data">
        <thead>
        <tr>
            @foreach ($columns as $column)
                <th @class(['num' => $column['numeric'] ?? false])>{{ $column['label'] }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        {{ $slot }}
        </tbody>
    </table>

    @if ($empty)
        <div class="empty">{{ $emptyMessage }}</div>
    @endif
</div>
