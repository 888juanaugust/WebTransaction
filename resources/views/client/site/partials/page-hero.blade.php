{{-- The heading band of an inner page: the title and one sentence under it. --}}
<section class="site-page-hero">
    <h1 class="site-page-hero__title">{{ $title }}</h1>
    @if (! empty($lede))
        <p class="site-page-hero__lede">{{ $lede }}</p>
    @endif
</section>
