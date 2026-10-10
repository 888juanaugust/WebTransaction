{{-- The company's mark beside its name in the topbar; the mark is config('client.theme.logo'). --}}
<span class="ae-brand">
    <img src="{{ asset($logo) }}" alt="" class="ae-brand-mark" aria-hidden="true" />
    <span class="ae-brand-name">{{ $name }}</span>
</span>
