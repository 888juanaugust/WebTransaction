{{-- The company's mark beside its name in the topbar: config('client.theme.logo') and its dark twin. --}}
<span class="ae-brand">
    <img src="{{ asset($logo) }}" alt="" class="ae-brand-mark ae-brand-mark-light" aria-hidden="true" />
    <img src="{{ asset($logoDark ?: $logo) }}" alt="" class="ae-brand-mark ae-brand-mark-dark" aria-hidden="true" />
    <span class="ae-brand-name">{{ $name }}</span>
</span>
