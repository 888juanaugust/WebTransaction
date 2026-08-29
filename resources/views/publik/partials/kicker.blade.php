{{--
    Section opener, used identically on every white section: red kicker line,
    navy title, optional grey lede. One pattern, so the pages read as one
    site. Expects: $kicker, $judul, $lede (optional).
--}}
<p class="flex items-center gap-3 text-xs font-semibold uppercase tracking-[0.2em] text-accent-600">
    <span class="h-px w-8 bg-accent-600" aria-hidden="true"></span>
    {{ $kicker }}
</p>
<h2 class="mt-3 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $judul }}</h2>
@isset($lede)
    <p class="mt-3 max-w-2xl leading-relaxed text-slate-600">{{ $lede }}</p>
@endisset
