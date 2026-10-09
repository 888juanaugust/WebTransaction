{{-- The carousel: the Owner's live promos, newest arrangement first. One slide needs no script; several rotate from site.js. --}}
@php use App\Client\Site\Copy; @endphp
<section class="site-promos" data-carousel aria-roledescription="carousel" aria-label="{{ __('Promotions') }}">
    <div class="site-promos__track">
        @foreach ($promos as $i => $promo)
            @php
                $title = (string) Copy::pick($promo->title);
                $text = (string) Copy::pick($promo->text ?? []);
                $link = $promo->link ? (str_starts_with($promo->link, '/') ? url($promo->link) : $promo->link) : null;
            @endphp
            <article @class(['site-promo', 'is-active' => $i === 0]) data-slide aria-hidden="{{ $i === 0 ? 'false' : 'true' }}" aria-roledescription="slide" aria-label="{{ $title }}">
                <img class="site-promo__image" src="{{ $promo->url() }}" alt="" width="1600" height="640" @if ($i > 0) loading="lazy" @endif>
                <div class="site-promo__body">
                    <h2 class="site-promo__title">{{ $title }}</h2>
                    @if ($text !== '')
                        <p class="site-promo__text">{{ $text }}</p>
                    @endif
                    @if ($link)
                        <a href="{{ $link }}" class="site-btn site-btn--on-accent">{{ __('See the offer') }} @include('client.site.partials.arrow')</a>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
    @if (count($promos) > 1)
        <div class="site-promos__controls">
            <button type="button" class="site-promos__arrow" data-prev aria-label="{{ __('Previous slide') }}"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.5 4 6.5 10l6 6"/></svg></button>
            <div class="site-promos__dots">
                @foreach ($promos as $i => $promo)
                    <button type="button" class="site-promos__dot" data-dot aria-current="{{ $i === 0 ? 'true' : 'false' }}" aria-label="{{ __('Slide :number', ['number' => $i + 1]) }}"></button>
                @endforeach
            </div>
            <button type="button" class="site-promos__arrow" data-next aria-label="{{ __('Next slide') }}"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m7.5 4 6 6-6 6"/></svg></button>
        </div>
    @endif
</section>
