{{--
    A status pill. `tone` is one of ok / warn / bad / info / muted; callers that
    pass a raw status string get `muted`, which is the honest default for a
    status this component has never been told how to read.
--}}
@props([
    'value' => '',
    'tone' => 'muted',
])
<span class="badge {{ $tone }}">{{ $value }}</span>
