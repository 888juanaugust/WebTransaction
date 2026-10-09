@extends('client.site.layout')

@php use App\Client\Site\Copy; @endphp

@section('title', __('Roadmap'))
@section('description', __('What :name has built and what comes next.', ['name' => Copy::name()]))

@section('content')

    @include('client.site.partials.page-hero', ['title' => __('Roadmap'), 'lede' => __('What we have built for our customers, and what comes next.')])

    <section class="site-section site-section--top site-section--narrow">
        <ol class="site-list">
            @foreach ($items as $item)
                @php
                    [$label, $tone] = match ($item['status'] ?? 'planned') {
                        'done' => [__('Done'), 'success'],
                        'ongoing' => [__('In progress'), 'info'],
                        default => [__('Planned'), 'neutral'],
                    };
                @endphp
                <li class="site-list__row site-list__row--roadmap">
                    <span class="site-badge site-badge--{{ $tone }}">{{ $label }}</span>
                    <div>
                        <h2 class="site-list__title site-list__title--lg">{{ $item['title'] }}</h2>
                        <p class="site-list__text">{{ $item['description'] }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
        <p class="site-note">{{ __('Dates are not promised: an item moves to Done when it works for our customers.') }}</p>
    </section>

@endsection
