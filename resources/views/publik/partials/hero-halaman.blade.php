{{--
    The inner pages' shared opening band. Same ground as the page, a title
    and one line of context, and a hairline underneath so the page has a
    clear top without changing colour. Expects: $judul, $lede (optional).
--}}
<section class="border-b border-line">
    <div class="mx-auto max-w-6xl px-4 pt-16 pb-12 sm:pt-24 sm:pb-16">
        <h1 class="max-w-3xl text-4xl font-semibold tracking-[-0.03em] text-ink sm:text-5xl">{{ $judul }}</h1>
        @isset($lede)
            <p class="mt-4 max-w-2xl text-lg leading-relaxed text-ink-muted">{{ $lede }}</p>
        @endisset
    </div>
</section>
