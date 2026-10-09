@extends('client.site.layout')

@php use App\Client\Site\Copy; @endphp

@section('title', __('Sign in'))
@section('description', __('Sign in to the :name customer portal or the staff panel.', ['name' => Copy::name()]))

@section('content')

    {{-- Two doors, not one form that guesses: staff and buyers sign in on different guards against different tables. --}}
    <section class="site-section site-section--top site-section--narrow">
        <div class="site-center">
            <h1 class="site-page-hero__title">{{ __('Sign in') }}</h1>
            <p class="site-page-hero__lede">{{ __('Choose the door that is yours.') }}</p>
        </div>

        <div class="site-doors">
            <a href="{{ $portalLogin }}" class="site-door site-door--primary">
                <span class="site-badge site-badge--on-accent">{{ __('Customers') }}</span>
                <h2 class="site-door__title">{{ __('Customer portal') }}</h2>
                <p class="site-door__text">{{ __('Repeat orders, your prices, free credit, invoices and delivery notes.') }}</p>
                <span class="site-door__cta">{{ __('Sign in as a customer') }} @include('client.site.partials.arrow')</span>
            </a>
            <a href="{{ $adminLogin }}" class="site-door">
                <span class="site-badge site-badge--neutral">{{ __('Staff') }}</span>
                <h2 class="site-door__title">{{ __('Staff panel') }}</h2>
                <p class="site-door__text">{{ __('Orders, stock, invoices, collections and the books.') }}</p>
                <span class="site-door__cta">{{ __('Sign in as staff') }} @include('client.site.partials.arrow')</span>
            </a>
        </div>

        <p class="site-note site-note--center">{{ __('No account yet?') }} <a href="{{ route('site.contact') }}" class="site-link">{{ __('Contact us') }}</a> {{ __('to open one; there is no self-registration.') }}</p>
    </section>

@endsection
