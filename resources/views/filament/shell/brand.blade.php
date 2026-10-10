{{-- The company's mark alone in the topbar, as the previous system drew it; the name stays for screen readers. --}}
<span class="ae-brand" role="img" aria-label="{{ $name }}" title="{{ $name }}">
    <img src="{{ asset($logo) }}" alt="" class="ae-brand-mark ae-brand-mark-light" />
    <img src="{{ asset($logoDark ?: $logo) }}" alt="" class="ae-brand-mark ae-brand-mark-dark" />
</span>
