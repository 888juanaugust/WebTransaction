{{--
    Section opener, used identically on every section: a title and an
    optional lede, left-aligned, no label above it — the section's place on
    the page already says what it is. Expects: $judul, $lede (optional).
--}}
<h2 class="max-w-2xl text-3xl font-semibold tracking-[-0.03em] text-ink sm:text-4xl">{{ $judul }}</h2>
@isset($lede)
    <p class="mt-3 max-w-2xl text-[17px] leading-relaxed text-ink-muted">{{ $lede }}</p>
@endisset
