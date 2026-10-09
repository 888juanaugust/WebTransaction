@extends('client.site.layout')

@php use App\Client\Site\Copy; @endphp

@section('title', __('About us'))
@section('description', __(':name: who we are, who we serve and how an account opens.', ['name' => Copy::name()]))

@section('content')

    @include('client.site.partials.page-hero', ['title' => __('About us'), 'lede' => Copy::text('tagline')])

    <section class="site-section site-section--top">
        <div class="site-two-col site-two-col--wide-left">
            <div>
                <h2 class="site-h2">{{ __('Company profile') }}</h2>
                @foreach (Copy::list('profile') as $paragraph)
                    <p class="site-p">{{ $paragraph }}</p>
                @endforeach

                <h2 class="site-h2 site-h2--spaced">{{ __('Who we serve') }}</h2>
                <dl class="site-dl">
                    @foreach (Copy::records('serves') as $segment)
                        <div class="site-dl__row">
                            <dt>{{ $segment['title'] }}</dt>
                            <dd>{{ $segment['text'] }}</dd>
                        </div>
                    @endforeach
                </dl>

                <h2 class="site-h2 site-h2--spaced">{{ __('How an account opens') }}</h2>
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
            </div>

            <aside class="site-card">
                <h2 class="site-h3">{{ __('Company details') }}</h2>
                <dl class="site-facts">
                    <div><dt>{{ __('Legal name') }}</dt><dd>{{ Copy::name() }}</dd></div>
                    <div><dt>{{ __('Entity') }}</dt><dd>{{ Copy::value('legal.entity') }}</dd></div>
                    @if (Copy::value('legal.nib'))
                        <div><dt>{{ __('NIB') }}</dt><dd>{{ Copy::value('legal.nib') }}</dd></div>
                    @endif
                    @if (Copy::value('legal.established'))
                        <div><dt>{{ __('Established') }}</dt><dd>{{ Copy::value('legal.established') }}</dd></div>
                    @endif
                    <div><dt>{{ __('Head office') }}</dt><dd>{{ Copy::value('contact.city') }}</dd></div>
                    <div><dt>{{ __('Brands') }}</dt><dd>{{ implode(', ', Copy::list('brands')) }}</dd></div>
                </dl>
                <a href="{{ route('site.contact') }}" class="site-btn site-btn--primary site-btn--block">{{ __('Contact us') }}</a>
            </aside>
        </div>
    </section>

@endsection
