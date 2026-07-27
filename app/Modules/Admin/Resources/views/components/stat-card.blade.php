@props([
    'label' => '',
    'value' => '',
    'delta' => null,
    'tone' => 'flat',
])
<div class="stat">
    <div class="label">{{ $label }}</div>
    <div class="value">{{ $value }}</div>
    @if ($delta !== null)
        <div class="delta {{ $tone }}">{{ $delta }}</div>
    @endif
</div>
