{{--
    The inner pages' shared opening band: dark navy like the home hero, so
    every page opens on the brand, with a small red kicker naming where the
    reader is. Expects: $kicker, $judul, $lede (optional).
--}}
<section class="bg-gradient-to-br from-brand-900 via-brand-800 to-brand-700">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:py-20">
        <p class="flex items-center gap-3 text-xs font-semibold uppercase tracking-[0.2em] text-brand-200">
            <span class="h-px w-8 bg-accent-600" aria-hidden="true"></span>
            {{ $kicker }}
        </p>
        <h1 class="mt-4 text-3xl font-bold tracking-tight text-white sm:text-4xl">{{ $judul }}</h1>
        @isset($lede)
            <p class="mt-4 max-w-2xl text-lg leading-relaxed text-brand-100">{{ $lede }}</p>
        @endisset
    </div>
</section>
