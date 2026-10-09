@extends('client.site.layout')

@php use App\Client\Site\Copy; @endphp

@section('description', Copy::text('summary'))

@section('content')

    @includeWhen(! empty($promos), 'client.site.partials.promos', ['promos' => $promos ?? []])

    {{-- The company, and nothing but the company: one statement, two actions, and where it is. --}}
    <section class="site-hero">
        <div class="site-hero__glow" aria-hidden="true"></div>
        <h1 class="site-hero__title">{{ Copy::text('tagline') }}</h1>
        <p class="site-hero__lede">{{ Copy::text('summary') }}</p>
        <div class="site-hero__actions">
            <a href="{{ route('site.sign_in') }}" class="site-btn site-btn--primary site-btn--lg">{{ __('Sign in to your account') }}</a>
            <a href="{{ route('site.contact') }}" class="site-btn site-btn--secondary site-btn--lg">{{ __('Become a customer') }}</a>
        </div>
        @if ($branches->isNotEmpty())
            <p class="site-hero__branches">
                <span>{{ __('Shipping from :count branches:', ['count' => $branches->count()]) }}</span>
                @foreach ($branches as $branch)
                    <span class="site-chip">{{ $branch->name }}</span>
                @endforeach
            </p>
        @endif
    </section>

    {{-- What we sell: four categories, a wide and narrow rhythm rather than a row of equal cards. --}}
    <section class="site-section" data-reveal>
        <div class="site-section__head">
            <h2 class="site-h2">{{ __('Product categories') }}</h2>
            <p class="site-lede">{{ __('The parts a workshop replaces most often, carried in depth rather than in breadth.') }}</p>
        </div>
        <div class="site-categories">
            @foreach ($categories as $i => $category)
                <article @class(['site-category', 'site-category--wide' => in_array($i % 4, [0, 3], true), 'site-category--tinted' => $i % 4 === 0])>
                    <span class="site-category__icon">@include('client.site.partials.category-icon', ['name' => $category['name']])</span>
                    <h3 class="site-category__name">{{ $category['name'] }}</h3>
                    <p class="site-category__text">{{ $category['description'] }}</p>
                </article>
            @endforeach
        </div>
        <p class="site-note">{{ __('The full list with your own prices is in the customer portal after sign-in.') }}</p>
    </section>

    {{-- Brands: the names are the content. --}}
    <section class="site-section site-section--tight" data-reveal>
        <h2 class="site-h2 site-h2--center">{{ __('Brands we carry') }}</h2>
        <ul class="site-brands">
            @foreach (Copy::list('brands') as $brand)
                <li class="site-brand-tile">{{ $brand }}</li>
            @endforeach
        </ul>
    </section>

    {{-- Who buys from us, and how an account opens: the distance between interested and ordering. --}}
    <section class="site-section" data-reveal>
        <div class="site-two-col">
            <div>
                <h2 class="site-h2">{{ __('Who we serve') }}</h2>
                <dl class="site-dl">
                    @foreach (Copy::records('serves') as $segment)
                        <div class="site-dl__row">
                            <dt>{{ $segment['title'] }}</dt>
                            <dd>{{ $segment['text'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
            <div>
                <h2 class="site-h2">{{ __('Become a customer in three steps') }}</h2>
                <ol class="site-steps">
                    @foreach (Copy::records('steps') as $i => $step)
                        <li class="site-step">
                            <span class="site-step__number" aria-hidden="true">{{ $i + 1 }}</span>
                            <div>
                                <h3 class="site-step__title">{{ $step['title'] }}</h3>
                                <p class="site-step__text">{{ $step['text'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
                <a href="{{ route('site.contact') }}" class="site-link">{{ __('Contact us') }} @include('client.site.partials.arrow')</a>
            </div>
        </div>
    </section>

    {{-- About, with the partners condensed beside it. --}}
    <section class="site-section" data-reveal>
        <div class="site-two-col">
            <div>
                <h2 class="site-h2">{{ __('About the company') }}</h2>
                @foreach (array_slice(Copy::list('profile'), 0, 2) as $paragraph)
                    <p class="site-p">{{ $paragraph }}</p>
                @endforeach
                <a href="{{ route('site.about') }}" class="site-link">{{ __('More about us') }} @include('client.site.partials.arrow')</a>
            </div>
            <div>
                <div class="site-row-head">
                    <h2 class="site-h3">{{ __('Our partners') }}</h2>
                    <a href="{{ route('site.partners') }}" class="site-link site-link--sm">{{ __('All partners') }} @include('client.site.partials.arrow')</a>
                </div>
                <ul class="site-list">
                    @foreach ($partners as $partner)
                        <li class="site-list__row site-list__row--partner">
                            <div>
                                <p class="site-list__title">{{ $partner['name'] }}</p>
                                <p class="site-list__text">{{ $partner['description'] }}</p>
                            </div>
                            <p class="site-list__meta">{{ $partner['field'] }}</p>
                            @if (! empty($partner['since']))
                                <p class="site-list__meta site-list__meta--end">{{ __('Since :year', ['year' => $partner['since']]) }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    {{-- The closing action: the one blue surface on the page. --}}
    <section class="site-section" data-reveal>
        <div class="site-cta">
            <div>
                <h2 class="site-cta__title">{{ __('Already a customer?') }}</h2>
                <p class="site-cta__lede">{{ __('Repeat your last order, check your free credit and download your invoices in the portal.') }}</p>
            </div>
            <a href="{{ route('site.sign_in') }}" class="site-btn site-btn--on-accent site-btn--lg">{{ __('Sign in to your account') }}</a>
        </div>
    </section>

@endsection
