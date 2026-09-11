{{--
    Public site layout.

    Open and indexed — this is the only surface search engines see. No prices
    appear anywhere on it: public price display is out of scope for v1, and
    wholesale pricing is per-customer by definition.

    Bilingual (2026-09): Bahasa Indonesia by default, English on request via
    the switch in the header, remembered in a cookie for a year. Every word of
    chrome comes from lang/{id,en}/publik.php; every word about the company
    comes from config/perusahaan.php as a pair, resolved by App\Support\
    Perusahaan. The two legal pages are the exception — instruments under
    Indonesian law, they stay in Bahasa Indonesia and declare their own
    `lang` whatever the visitor chose. The panels and every printed document
    stay Indonesian too: they are for staff and buyers, in the words staff and
    buyers actually use.

    The visual language, deliberately: a white ground, white surfaces with a
    hairline border, black text, and company blue as the only accent. The
    mark and the name carry the brand; red is the panels' danger colour and
    never shows up here as decoration. No section flips to a dark background
    — the page reads as one surface from the top to the footer.
--}}
@php
    use App\Http\Middleware\PublicLocale;

    $bahasa = app()->getLocale();
    $bahasaLain = PublicLocale::lainnya();
@endphp
<!DOCTYPE html>
<html lang="@yield('lang', $bahasa)" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- The shopfront commits to light; see color-scheme in app.css. --}}
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#ffffff">

    <title>@yield('judul', config('perusahaan.nama')) · {{ config('perusahaan.nama') }}</title>
    <meta name="description" content="@yield('deskripsi', \App\Support\Perusahaan::text('ringkasan'))">

    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:site_name" content="{{ config('perusahaan.nama') }}">
    <meta property="og:title" content="@yield('judul', config('perusahaan.nama'))">
    <meta property="og:description" content="@yield('deskripsi', \App\Support\Perusahaan::text('ringkasan'))">
    <meta property="og:locale" content="{{ $bahasa === 'en' ? 'en_US' : 'id_ID' }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    @if (\App\Support\Branding::hasLogo())
        <link rel="icon" type="image/svg+xml" href="{{ \App\Support\Branding::logoUrl() }}">
    @endif

    {{--
        Organization schema, from the same config the footer prints. Search
        engines get the legal name, the city and the phone number — nothing
        that is not already on the page in prose.

        HEX_TAG and friends because these values are typed by the Owner into
        Pengaturan: an address containing `</script>` must not be able to
        close this block and run on the public page. The Owner is trusted,
        but a text field is not a place to store that trust.

        Raw PHP tags, not a Blade echo, and that is load-bearing: Blade
        compiles `@context` — schema.org's required key — as its own
        \@context directive when it appears in template text, which mangled
        this block's first key into compiled-PHP soup. Content inside real
        `<?php ?>` tags is never scanned for directives. The audit test
        pins the key surviving intact.
    --}}
    <script type="application/ld+json"><?php echo json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => config('perusahaan.nama'),
        'url' => url('/'),
        'telephone' => config('perusahaan.kontak.telepon'),
        'email' => config('perusahaan.kontak.email'),
        'address' => [
            '@type' => 'PostalAddress',
            'streetAddress' => config('perusahaan.kontak.alamat'),
            'addressLocality' => config('perusahaan.kontak.kota'),
            'addressCountry' => 'ID',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

    {{-- Self-hosted; see app.css. Preloaded because it paints the whole page. --}}
    <link rel="preload" href="{{ asset('fonts/Geist-Variable.woff2') }}" as="font" type="font/woff2" crossorigin>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Marks the document as scripted so .reveal only ever hides with JS present. --}}
    <script nonce="{{ $cspNonce ?? '' }}">document.documentElement.classList.add('js')</script>
</head>
<body class="flex min-h-dvh flex-col bg-ground text-ink antialiased">

    <a href="#konten" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50
        focus:rounded-btn focus:bg-brand-600 focus:px-4 focus:py-2 focus:text-white">
        {{ __('publik.menu.lewati') }}
    </a>

    @php
        $menu = [
            'publik.beranda' => __('publik.menu.beranda'),
            'publik.tentang' => __('publik.menu.tentang'),
            'publik.mitra' => __('publik.menu.mitra'),
            'publik.rencana' => __('publik.menu.rencana'),
            'publik.kontak' => __('publik.menu.kontak'),
        ];
    @endphp

    {{--
        A floating bar rather than a full-width band: the page ground shows on
        either side, so the header reads as a control sitting on the surface.
        Sticky, with a blur so content sliding under it stays legible.
    --}}
    <header class="sticky top-0 z-40 px-4 pt-3 sm:pt-5">
        <nav class="mx-auto flex h-14 max-w-6xl items-center justify-between gap-4 rounded-[14px] border
                    border-line bg-white/80 pr-2 pl-4 shadow-card backdrop-blur-md sm:h-[60px] sm:pl-5"
             aria-label="{{ __('publik.menu.utama') }}">
            {{-- The mark if there is one, the wordmark either way. --}}
            <a href="{{ route('publik.beranda') }}" class="flex items-center gap-2.5 text-[15px] font-semibold tracking-tight text-ink">
                @if (\App\Support\Branding::hasLogo())
                    <img src="{{ \App\Support\Branding::logoUrl() }}" alt="" aria-hidden="true" class="h-6 w-auto">
                @endif
                {{ config('perusahaan.nama_singkat') }}
            </a>

            {{-- The current page is the one link in ink; the rest sit back in grey. --}}
            <div class="hidden items-center gap-7 md:flex">
                @foreach ($menu as $rute => $label)
                    <a href="{{ route($rute) }}"
                       @class([
                           'text-sm font-medium transition-colors duration-200',
                           'text-ink' => request()->routeIs($rute),
                           'text-ink-muted hover:text-ink' => ! request()->routeIs($rute),
                       ])
                       @if (request()->routeIs($rute)) aria-current="page" @endif>
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <div class="flex items-center gap-1.5">
                {{--
                    The language switch: one link, to the other language. A
                    two-way toggle would show the language you are already
                    reading as a button, which is a button that does nothing.
                --}}
                <a href="{{ route('publik.bahasa', $bahasaLain) }}"
                   hreflang="{{ $bahasaLain }}"
                   lang="{{ $bahasaLain }}"
                   aria-label="{{ __('publik.ganti_bahasa_label') }}"
                   class="hidden rounded-[9px] px-2.5 py-2 text-sm font-medium text-ink-muted transition
                          hover:bg-ground-2 hover:text-ink sm:inline-block"
                   id="ganti-bahasa">
                    {{ strtoupper($bahasaLain) }}
                </a>

                <a href="{{ route('masuk') }}"
                   class="rounded-[9px] bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-btn
                          transition duration-200 hover:bg-brand-500 active:scale-[0.98]
                          focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                    {{ __('publik.menu.masuk') }}
                </a>

                {{-- Hamburger. Plain button + hidden panel: no framework, no fetch. --}}
                <button id="tombol-menu" type="button"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-[9px] text-ink-muted
                               transition hover:bg-ground-2 hover:text-ink md:hidden"
                        aria-expanded="false" aria-controls="menu-seluler" aria-label="{{ __('publik.menu.buka') }}"
                        data-label-buka="{{ __('publik.menu.buka') }}" data-label-tutup="{{ __('publik.menu.tutup') }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                    </svg>
                </button>
            </div>
        </nav>

        <div id="menu-seluler" class="mx-auto mt-2 hidden max-w-6xl rounded-[14px] border border-line bg-white p-2 shadow-card md:hidden" hidden>
            <div class="grid gap-0.5">
                @foreach ($menu as $rute => $label)
                    <a href="{{ route($rute) }}"
                       @class([
                           'rounded-[9px] px-3 py-2.5 text-sm font-medium',
                           'bg-ground-2 text-ink' => request()->routeIs($rute),
                           'text-ink-muted hover:bg-ground hover:text-ink' => ! request()->routeIs($rute),
                       ])>{{ $label }}</a>
                @endforeach
                <a href="{{ route('publik.bahasa', $bahasaLain) }}" hreflang="{{ $bahasaLain }}" lang="{{ $bahasaLain }}"
                   class="rounded-[9px] px-3 py-2.5 text-sm font-medium text-ink-muted hover:bg-ground hover:text-ink">
                    {{ __('publik.ganti_bahasa') }}
                </a>
            </div>
        </div>
    </header>

    <main id="konten" class="flex-1">
        @yield('konten')
    </main>

    {{-- The footer stays on the same ground as the page: a hairline, four columns, no colour flip. --}}
    <footer class="mt-24 px-4 pb-10">
        <div class="mx-auto max-w-6xl border-t border-line pt-10">
            <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1.4fr]">
                <div>
                    <p class="flex items-center gap-2.5 text-[15px] font-semibold text-ink">
                        @if (\App\Support\Branding::hasLogo())
                            <img src="{{ \App\Support\Branding::logoUrl() }}" alt="" aria-hidden="true" class="h-5 w-auto">
                        @endif
                        {{ config('perusahaan.nama') }}
                    </p>
                    <p class="mt-3 max-w-sm text-sm leading-relaxed text-ink-muted">
                        {{ \App\Support\Perusahaan::text('ringkasan') }}
                    </p>
                    @if (config('perusahaan.legal.nib'))
                        <p class="mt-4 text-xs text-ink-faint">NIB {{ config('perusahaan.legal.nib') }}</p>
                    @endif
                </div>

                <div>
                    <p class="text-sm font-semibold text-ink">{{ __('publik.kaki.halaman') }}</p>
                    <ul class="mt-3 space-y-2.5 text-sm text-ink-muted">
                        @foreach (array_slice($menu, 1, null, true) as $rute => $label)
                            <li><a class="transition-colors hover:text-ink" href="{{ route($rute) }}">{{ $label }}</a></li>
                        @endforeach
                    </ul>
                </div>

                <div>
                    <p class="text-sm font-semibold text-ink">{{ __('publik.kaki.hukum') }}</p>
                    <ul class="mt-3 space-y-2.5 text-sm text-ink-muted">
                        <li><a class="transition-colors hover:text-ink" href="{{ route('publik.privasi') }}">{{ __('publik.kaki.privasi') }}</a></li>
                        <li><a class="transition-colors hover:text-ink" href="{{ route('publik.syarat') }}">{{ __('publik.kaki.syarat') }}</a></li>
                    </ul>
                </div>

                <div>
                    <p class="text-sm font-semibold text-ink">{{ __('publik.kaki.kontak') }}</p>
                    <ul class="mt-3 space-y-2.5 text-sm text-ink-muted">
                        <li>{{ config('perusahaan.kontak.telepon') }}</li>
                        <li>
                            <a class="transition-colors hover:text-ink" href="mailto:{{ config('perusahaan.kontak.email') }}">
                                {{ config('perusahaan.kontak.email') }}
                            </a>
                        </li>
                        <li class="leading-relaxed">{{ config('perusahaan.kontak.alamat') }}</li>
                    </ul>
                </div>
            </div>

            <div class="mt-10 flex flex-col gap-2 border-t border-line pt-6 text-xs text-ink-faint sm:flex-row sm:justify-between">
                <p>&copy; {{ date('Y') }} {{ config('perusahaan.nama') }}. {{ __('publik.kaki.hak_cipta') }}</p>
                <p>{{ __('publik.kaki.harga_grosir') }}</p>
            </div>
        </div>
    </footer>

    @include('publik.partials.persetujuan')

    @stack('kaki')

    <script nonce="{{ $cspNonce ?? '' }}">
        (() => {
            const tombol = document.getElementById('tombol-menu');
            const panel = document.getElementById('menu-seluler');
            tombol?.addEventListener('click', () => {
                panel.hidden = ! panel.hidden;
                panel.classList.toggle('hidden', panel.hidden);
                tombol.setAttribute('aria-expanded', String(! panel.hidden));
                tombol.setAttribute('aria-label', panel.hidden ? tombol.dataset.labelBuka : tombol.dataset.labelTutup);
            });

            // Scroll-entry reveal: settle each .reveal once it is a fifth in view.
            const io = 'IntersectionObserver' in window ? new IntersectionObserver((entries) => {
                for (const e of entries) {
                    if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); }
                }
            }, { threshold: 0.2 }) : null;
            document.querySelectorAll('.reveal').forEach((el) => io ? io.observe(el) : el.classList.add('is-in'));
        })();
    </script>

</body>
</html>
