{{--
    The public site's layout: the one surface search engines see. Bilingual
    (Bahasa Indonesia by default, English from the switch in the header,
    remembered in a cookie), never a price. Every word of chrome goes
    through __(); every word about the company comes from the site's copy
    (App\Client\Site\Copy) in the language being spoken. The two legal pages
    render the whole page in Indonesian whatever the visitor chose.

    Same world as the workspace (docs/design/DESIGN.md): the cool-grey canvas,
    white surfaces with a faint warm edge, one blue accent, Geist. Light by
    default, dark with the system. No inline script or style: the site runs
    under its own strict content policy (SiteContentSecurityPolicy).
--}}
@php
    use App\Client\Site\Copy;

    $locale = app()->getLocale();
    $other = Copy::otherLanguage();
    $name = Copy::name();
    $title = trim($__env->yieldContent('title'));
    $description = trim($__env->yieldContent('description')) ?: Copy::text('summary');
    $menu = [
        'site.home' => __('Home'),
        'site.about' => __('About us'),
        'site.partners' => __('Partners'),
        'site.roadmap' => __('Roadmap'),
        'site.contact' => __('Contact'),
    ];
    $branches = \App\Models\Company\Branch::query()->where('is_active', true)->orderBy('code')->get();
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#e3e7f2" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0f1219" media="(prefers-color-scheme: dark)">

    <title>{{ $title !== '' ? $title.' · '.$name : $name.' · '.Copy::text('tagline') }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset(config('client.theme.logo')) }}">

    <meta property="og:site_name" content="{{ $name }}">
    <meta property="og:title" content="{{ $title !== '' ? $title : $name }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:locale" content="{{ $locale === 'en' ? 'en_US' : 'id_ID' }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">

    {{--
        Organization schema from the same copy the footer prints. A data block
        the browser never executes, so the content policy has nothing to deny.
        Raw PHP tags on purpose: Blade would read schema.org's "@context" as a
        directive inside template text.
    --}}
    <script type="application/ld+json"><?php echo json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => $name,
        'url' => url('/'),
        'telephone' => (string) Copy::value('contact.phone'),
        'email' => (string) Copy::value('contact.email'),
        'location' => $branches->map(fn ($b) => array_filter([
            '@type' => 'Place',
            'name' => $b->name,
            'address' => $b->address ? ['@type' => 'PostalAddress', 'streetAddress' => $b->address, 'addressCountry' => 'ID'] : null,
            'telephone' => $b->phone_number ?: null,
        ]))->values()->all(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

    @vite(['resources/css/site.css', 'resources/js/site.js'])
</head>
<body class="site-body">

    <a href="#content" class="site-skip">{{ __('Go to the content') }}</a>

    <header class="site-header">
        <nav class="site-nav" aria-label="{{ __('Main menu') }}">
            <a href="{{ route('site.home') }}" class="site-brand">
                <img src="{{ asset(config('client.theme.logo')) }}" alt="" class="site-brand__mark site-brand__mark--light" aria-hidden="true">
                <img src="{{ asset(config('client.theme.logo_dark') ?: config('client.theme.logo')) }}" alt="" class="site-brand__mark site-brand__mark--dark" aria-hidden="true">
                <span>{{ Copy::shortName() }}</span>
            </a>

            <div class="site-nav__links">
                @foreach ($menu as $route => $label)
                    <a href="{{ route($route) }}" @class(['site-nav__link', 'is-current' => request()->routeIs($route)]) @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </div>

            <div class="site-nav__actions">
                {{-- One link, to the other language: a toggle showing the language you are reading is a button that does nothing. --}}
                <a href="{{ route('site.language', $other) }}" hreflang="{{ $other }}" lang="{{ $other }}" class="site-nav__lang" aria-label="{{ __('Switch language') }}">{{ strtoupper($other) }}</a>
                <a href="{{ route('site.sign_in') }}" class="site-btn site-btn--primary site-btn--sm">{{ __('Sign in') }}</a>
                <button type="button" class="site-nav__burger" aria-expanded="false" aria-controls="site-menu" aria-label="{{ __('Open menu') }}" data-site-menu-button data-label-open="{{ __('Open menu') }}" data-label-close="{{ __('Close menu') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
                </button>
            </div>
        </nav>

        <div id="site-menu" class="site-menu" hidden>
            @foreach ($menu as $route => $label)
                <a href="{{ route($route) }}" @class(['site-menu__link', 'is-current' => request()->routeIs($route)])>{{ $label }}</a>
            @endforeach
            <a href="{{ route('site.language', $other) }}" hreflang="{{ $other }}" lang="{{ $other }}" class="site-menu__link">{{ __('Read this site in :language', ['language' => \App\Domain\Shared\Locales::names()[$other]]) }}</a>
        </div>
    </header>

    <main id="content" class="site-main">
        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="site-footer__grid">
            <div>
                <p class="site-footer__name">{{ $name }}</p>
                <p class="site-footer__summary">{{ Copy::text('summary') }}</p>
                @if (Copy::value('legal.nib'))
                    <p class="site-footer__fine">{{ __('NIB :number', ['number' => Copy::value('legal.nib')]) }}</p>
                @endif
            </div>
            <div>
                <p class="site-footer__heading">{{ __('Pages') }}</p>
                <ul class="site-footer__list">
                    @foreach (array_slice($menu, 1, null, true) as $route => $label)
                        <li><a href="{{ route($route) }}">{{ $label }}</a></li>
                    @endforeach
                </ul>
            </div>
            <div>
                <p class="site-footer__heading">{{ __('Legal') }}</p>
                <ul class="site-footer__list">
                    <li><a href="{{ route('site.privacy') }}">{{ __('Privacy policy') }}</a></li>
                    <li><a href="{{ route('site.terms') }}">{{ __('Terms of sale') }}</a></li>
                </ul>
            </div>
            <div>
                <p class="site-footer__heading">{{ __('Contact') }}</p>
                <ul class="site-footer__list">
                    <li>{{ Copy::value('contact.phone') }}</li>
                    <li><a href="mailto:{{ Copy::value('contact.email') }}">{{ Copy::value('contact.email') }}</a></li>
                    @foreach ($branches as $branch)
                        <li>{{ $branch->name }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="site-footer__bottom">
            <p>{{ __('© :year :name. All rights reserved.', ['year' => date('Y'), 'name' => $name]) }}</p>
            <p>{{ __('Wholesale only. Prices are set per customer and never shown publicly.') }}</p>
        </div>
    </footer>

</body>
</html>
