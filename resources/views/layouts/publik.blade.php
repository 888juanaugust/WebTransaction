{{--
    Public site layout.

    Open and indexed — this is the only surface search engines see. No prices
    appear anywhere on it: public price display is out of scope for v1, and
    wholesale pricing is per-customer by definition.

    Bahasa Indonesia throughout. The site was briefly bilingual, with an English
    home page in front of Indonesian inner pages; that meant every nav label
    changed language depending on which page you were standing on, and the two
    versions of the company summary drifted apart in config. One language, one
    copy of each sentence.

    The visual language, deliberately: navy carries the weight (the hero and
    the footer are dark, so the page opens and closes with the brand), red
    appears only as a small kicker accent — it is the panels' danger colour,
    so here it stays an accent and never a surface — and everything between
    sits on white with soft-shadow cards rather than hairline boxes.
--}}
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- The shopfront commits to light; see color-scheme in app.css. --}}
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#073185">

    <title>@yield('judul', config('perusahaan.nama')) — {{ config('perusahaan.nama') }}</title>
    <meta name="description" content="@yield('deskripsi', \App\Support\Perusahaan::text('ringkasan'))">

    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:site_name" content="{{ config('perusahaan.nama') }}">
    <meta property="og:title" content="@yield('judul', config('perusahaan.nama'))">
    <meta property="og:description" content="@yield('deskripsi', \App\Support\Perusahaan::text('ringkasan'))">
    <meta property="og:locale" content="id_ID">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">

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

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col bg-white text-slate-800 antialiased">

    <a href="#konten" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50
        focus:rounded-md focus:bg-brand-600 focus:px-4 focus:py-2 focus:text-white">
        Lompat ke konten
    </a>

    @php
        $menu = [
            'publik.beranda' => 'Beranda',
            'publik.tentang' => 'Tentang Kami',
            'publik.mitra' => 'Mitra',
            'publik.rencana' => 'Rencana',
            'publik.kontak' => 'Kontak',
        ];
    @endphp

    <header class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/90 backdrop-blur">
        <nav class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4" aria-label="Utama">
            {{-- The mark if there is one, the wordmark if there is not. --}}
            <a href="{{ route('publik.beranda') }}"
               class="flex items-center gap-2.5 text-lg font-bold tracking-tight text-brand-600">
                @if (\App\Support\Branding::hasLogo())
                    <img src="{{ \App\Support\Branding::logoUrl() }}"
                         alt="{{ config('perusahaan.nama') }}" class="h-9 w-auto">
                @endif
                {{ config('perusahaan.nama_singkat') }}
            </a>

            {{--
                Active page marked by a red underline, not a filled pill: the
                underline reads at a glance without adding another surface to
                a bar that already holds a logo and a button.
            --}}
            <div class="hidden items-center gap-6 md:flex">
                @foreach ($menu as $rute => $label)
                    <a href="{{ route($rute) }}"
                       @class([
                           'relative py-1.5 text-sm font-medium transition',
                           'text-brand-700 after:absolute after:inset-x-0 after:-bottom-0.5 after:h-0.5 after:rounded-full after:bg-accent-600'
                               => request()->routeIs($rute),
                           'text-slate-600 hover:text-brand-700' => ! request()->routeIs($rute),
                       ])
                       @if (request()->routeIs($rute)) aria-current="page" @endif>
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ route('masuk') }}"
                   class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm
                          transition hover:bg-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-600
                          focus:ring-offset-2">
                    Masuk
                </a>

                {{-- Hamburger. Plain button + hidden panel: no framework, no fetch. --}}
                <button id="tombol-menu" type="button"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-lg text-slate-600
                               transition hover:bg-slate-100 hover:text-brand-700 md:hidden"
                        aria-expanded="false" aria-controls="menu-seluler" aria-label="Buka menu">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                    </svg>
                </button>
            </div>
        </nav>

        <div id="menu-seluler" class="hidden border-t border-slate-100 bg-white px-4 py-3 md:hidden" hidden>
            <div class="grid gap-1">
                @foreach ($menu as $rute => $label)
                    <a href="{{ route($rute) }}"
                       @class([
                           'rounded-lg px-3 py-2.5 text-sm font-medium',
                           'bg-brand-50 text-brand-700' => request()->routeIs($rute),
                           'text-slate-700 hover:bg-slate-50' => ! request()->routeIs($rute),
                       ])>{{ $label }}</a>
                @endforeach
            </div>
        </div>
    </header>

    <main id="konten" class="flex-1">
        @yield('konten')
    </main>

    {{--
        The footer closes the page the way the hero opens it: on the brand.
        Dark navy, light text, and the same four columns as before.
    --}}
    <footer class="mt-24 bg-brand-900 text-slate-300">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-14 sm:grid-cols-2 lg:grid-cols-5">
            <div class="sm:col-span-2">
                <p class="text-lg font-bold text-white">{{ config('perusahaan.nama') }}</p>
                <p class="mt-3 max-w-md text-sm leading-relaxed text-slate-400">
                    {{ \App\Support\Perusahaan::text('ringkasan') }}
                </p>
                @if (config('perusahaan.legal.nib'))
                    <p class="mt-5 text-xs text-slate-500">NIB {{ config('perusahaan.legal.nib') }}</p>
                @endif
            </div>

            <div>
                <p class="text-sm font-semibold text-white">Halaman</p>
                <ul class="mt-4 space-y-2.5 text-sm">
                    @foreach (array_slice($menu, 1, null, true) as $rute => $label)
                        <li><a class="transition hover:text-white" href="{{ route($rute) }}">{{ $label }}</a></li>
                    @endforeach
                </ul>
            </div>

            <div>
                <p class="text-sm font-semibold text-white">Ketentuan</p>
                <ul class="mt-4 space-y-2.5 text-sm">
                    <li><a class="transition hover:text-white" href="{{ route('publik.privasi') }}">Kebijakan Privasi</a></li>
                    <li><a class="transition hover:text-white" href="{{ route('publik.syarat') }}">Syarat Penjualan</a></li>
                </ul>
            </div>

            <div>
                <p class="text-sm font-semibold text-white">Kontak</p>
                <ul class="mt-4 space-y-2.5 text-sm">
                    <li>{{ config('perusahaan.kontak.telepon') }}</li>
                    <li>
                        <a class="transition hover:text-white" href="mailto:{{ config('perusahaan.kontak.email') }}">
                            {{ config('perusahaan.kontak.email') }}
                        </a>
                    </li>
                    <li class="leading-relaxed text-slate-400">{{ config('perusahaan.kontak.alamat') }}</li>
                </ul>
            </div>
        </div>

        <div class="border-t border-white/10">
            <div class="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-6 text-xs text-slate-500 sm:flex-row sm:justify-between">
                <p>&copy; {{ date('Y') }} {{ config('perusahaan.nama') }}. Seluruh hak cipta dilindungi.</p>
                <p class="flex flex-wrap gap-x-4 gap-y-1">
                    <span>Harga grosir hanya untuk pelanggan terdaftar.</span>
                    <a class="transition hover:text-slate-300" href="{{ route('publik.privasi') }}">Kebijakan Privasi</a>
                    <a class="transition hover:text-slate-300" href="{{ route('publik.syarat') }}">Syarat Penjualan</a>
                </p>
            </div>
        </div>
    </footer>

    <script>
        (() => {
            const tombol = document.getElementById('tombol-menu');
            const panel = document.getElementById('menu-seluler');
            tombol?.addEventListener('click', () => {
                panel.hidden = ! panel.hidden;
                panel.classList.toggle('hidden', panel.hidden);
                tombol.setAttribute('aria-expanded', String(! panel.hidden));
                tombol.setAttribute('aria-label', panel.hidden ? 'Buka menu' : 'Tutup menu');
            });
        })();
    </script>

</body>
</html>
