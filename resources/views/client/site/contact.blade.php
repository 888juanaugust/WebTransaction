@extends('client.site.layout')

@php use App\Client\Site\Copy; @endphp

@section('title', __('Contact'))
@section('description', __('How to reach :name: phone, WhatsApp, email, and our branches.', ['name' => Copy::name()]))

@section('content')

    @include('client.site.partials.page-hero', ['title' => __('Contact'), 'lede' => __('To open a wholesale account or to reach your branch.')])

    <section class="site-section site-section--top">
        <div class="site-two-col">
            <div>
                <h2 class="site-h2">{{ __('Contact details') }}</h2>
                <dl class="site-dl site-dl--contact">
                    <div class="site-dl__row">
                        <dt>{{ __('Phone') }}</dt>
                        <dd><a href="tel:{{ preg_replace('/[^0-9+]/', '', (string) Copy::value('contact.phone')) }}">{{ Copy::value('contact.phone') }}</a></dd>
                    </div>
                    <div class="site-dl__row">
                        <dt>{{ __('WhatsApp') }}</dt>
                        <dd><a href="https://wa.me/{{ Copy::digits((string) Copy::value('contact.whatsapp')) }}" target="_blank" rel="noopener">{{ Copy::value('contact.whatsapp') }}</a></dd>
                    </div>
                    <div class="site-dl__row">
                        <dt>{{ __('Email') }}</dt>
                        <dd><a href="mailto:{{ Copy::value('contact.email') }}">{{ Copy::value('contact.email') }}</a></dd>
                    </div>
                    <div class="site-dl__row">
                        <dt>{{ __('Business hours') }}</dt>
                        <dd>{{ Copy::text('contact.hours') }}</dd>
                    </div>
                </dl>
            </div>

            <div class="site-stack">
                <div class="site-card">
                    <h2 class="site-h3">{{ __('Open a wholesale account') }}</h2>
                    <p class="site-p">{{ __('Send your business name, city and the parts you need. The sales team of your nearest branch gets back to you with prices and terms.') }}</p>
                    <a href="https://wa.me/{{ Copy::digits((string) Copy::value('contact.whatsapp')) }}" target="_blank" rel="noopener" class="site-btn site-btn--primary">{{ __('Message us on WhatsApp') }}</a>
                </div>
                <div class="site-card site-card--quiet">
                    <h2 class="site-h3">{{ __('Already have an account?') }}</h2>
                    <p class="site-p">{{ __('Your orders, invoices and delivery notes are in the portal.') }}</p>
                    <a href="{{ route('site.sign_in') }}" class="site-btn site-btn--secondary">{{ __('Sign in to your account') }}</a>
                </div>
            </div>
        </div>
    </section>

    {{-- Our branches: every customer is served by the one the admin assigned them to; stock ships from wherever it is. --}}
    @if ($branches->isNotEmpty())
        <section id="cabang" class="site-section" data-reveal>
            <div class="site-card site-card--wide">
                <h2 class="site-h2">{{ __('Our branches') }}</h2>
                <p class="site-lede">{{ __('Every customer is served by one branch and its sales team; goods ship from whichever warehouse holds them.') }}</p>
                <ul class="site-list">
                    @foreach ($branches as $branch)
                        <li class="site-list__row site-list__row--branch">
                            <p class="site-list__title">{{ $branch->name }}</p>
                            <p class="site-list__text">{{ $branch->address }}</p>
                            <p class="site-list__meta site-list__meta--end">
                                @if ($branch->phone_number)
                                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $branch->phone_number) }}">{{ $branch->phone_number }}</a>
                                @endif
                                @if ($branch->latitude !== null && $branch->longitude !== null)
                                    <a href="https://www.google.com/maps?q={{ $branch->latitude }},{{ $branch->longitude }}" target="_blank" rel="noopener" class="site-list__map">{{ __('Map') }}</a>
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

@endsection
