@extends('client.site.layout')

@php use App\Client\Site\Copy; @endphp

@section('title', __('Partners'))
@section('description', __('The suppliers, distributors and logistics partners :name works with.', ['name' => Copy::name()]))

@section('content')

    @include('client.site.partials.page-hero', ['title' => __('Partners'), 'lede' => __('The companies we build our supply and our reach with.')])

    <section class="site-section site-section--top">
        <ul class="site-list">
            @forelse ($partners as $partner)
                <li class="site-list__row site-list__row--partner-full">
                    <div>
                        <h2 class="site-list__title site-list__title--lg">{{ $partner['name'] }}</h2>
                        <p class="site-list__text">{{ $partner['description'] }}</p>
                    </div>
                    <div class="site-list__meta">
                        <p class="site-list__strong">{{ $partner['field'] }}</p>
                        @if (! empty($partner['country']))
                            <p>{{ $partner['country'] }}</p>
                        @endif
                    </div>
                    @if (! empty($partner['since']))
                        <p class="site-list__meta site-list__meta--end">{{ __('Since :year', ['year' => $partner['since']]) }}</p>
                    @endif
                </li>
            @empty
                <li class="site-list__row site-list__empty">{{ __('No partners listed yet.') }}</li>
            @endforelse
        </ul>

        <div class="site-card site-card--row">
            <div>
                <h2 class="site-h3">{{ __('Work with us') }}</h2>
                <p class="site-p">{{ __('Suppliers and regional distributors who want to carry or supply our brands are welcome to get in touch.') }}</p>
            </div>
            <a href="{{ route('site.contact') }}" class="site-btn site-btn--primary">{{ __('Contact us') }}</a>
        </div>
    </section>

@endsection
