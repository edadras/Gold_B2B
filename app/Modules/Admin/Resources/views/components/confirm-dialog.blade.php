{{--
    Confirmation gate for an irreversible action.

    A <details> element rather than a JavaScript modal: there is no bundler in
    this module's path, and a disclosure the operator must open before the
    submit button even exists is a real speed bump — which is the point on a
    screen that moves gold.
--}}
@props([
    'summary' => 'تأیید نهایی',
    'warning' => 'این اقدام برگشت‌ناپذیر است.',
])
<details class="confirm">
    <summary>{{ $summary }}</summary>
    <p class="warning">{{ $warning }}</p>
    {{ $slot }}
</details>
