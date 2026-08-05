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

    Colours: cream page, navy chrome, coral accent, powder panels.
--}}
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- The shopfront commits to light; see color-scheme in app.css. --}}
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#2b3467">

    <title>@yield('judul', config('perusahaan.nama')) — {{ config('perusahaan.nama') }}</title>
    <meta name="description" content="@yield('deskripsi', \App\Support\Perusahaan::text('ringkasan'))">

    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:site_name" content="{{ config('perusahaan.nama') }}">
    <meta property="og:title" content="@yield('judul', config('perusahaan.nama'))">
    <meta property="og:description" content="@yield('deskripsi', \App\Support\Perusahaan::text('ringkasan'))">
    <meta property="og:locale" content="id_ID">
    <meta property="og:type" content="website">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col bg-cream text-brand-900 antialiased">

    <a href="#konten" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50
        focus:rounded-md focus:bg-brand-600 focus:px-4 focus:py-2 focus:text-white">
        Lompat ke konten
    </a>

    @php
        $menu = [
            'publik.beranda' => ['Beranda', 'Beranda'],
            'publik.tentang' => ['Tentang Kami', 'Tentang'],
            'publik.mitra' => ['Mitra', 'Mitra'],
            'publik.rencana' => ['Rencana Pengembangan', 'Rencana'],
            'publik.kontak' => ['Kontak', 'Kontak'],
        ];
    @endphp

    <header class="sticky top-0 z-40 border-b-2 border-brand-600 bg-brand-600 text-white">
        <nav class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4" aria-label="Utama">
            <a href="{{ route('publik.beranda') }}" class="text-lg font-bold tracking-tight text-white">
                {{ config('perusahaan.nama_singkat') }}
            </a>

            <div class="hidden items-center gap-1 md:flex">
                @foreach ($menu as $rute => [$label, $labelPendek])
                    <a href="{{ route($rute) }}"
                       @class([
                           'rounded-md px-3 py-2 text-sm font-medium transition',
                           'bg-white/15 text-white' => request()->routeIs($rute),
                           'text-powder-200 hover:bg-white/10 hover:text-white' => ! request()->routeIs($rute),
                       ])
                       @if (request()->routeIs($rute)) aria-current="page" @endif>
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            {{-- Coral against navy: the one thing on the bar to click. --}}
            <a href="{{ route('masuk') }}"
               class="rounded-md bg-accent-600 px-4 py-2 text-sm font-semibold text-white shadow-sm
                      transition hover:bg-accent-700 focus:outline-none focus:ring-2 focus:ring-white
                      focus:ring-offset-2 focus:ring-offset-brand-600">
                Masuk
            </a>
        </nav>

        {{-- Mobile nav: the desktop row would wrap badly on a phone. --}}
        <div class="flex gap-1 overflow-x-auto border-t border-white/10 px-4 py-2 md:hidden">
            @foreach ($menu as $rute => [$label, $labelPendek])
                <a href="{{ route($rute) }}"
                   @class([
                       'shrink-0 rounded-md px-3 py-1.5 text-sm font-medium',
                       'bg-white/15 text-white' => request()->routeIs($rute),
                       'text-powder-200' => ! request()->routeIs($rute),
                   ])>{{ $labelPendek }}</a>
            @endforeach
        </div>
    </header>

    <main id="konten" class="flex-1">
        @yield('konten')
    </main>

    <footer class="mt-20 bg-brand-800 text-powder-100">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2">
                <p class="text-lg font-bold text-white">{{ config('perusahaan.nama') }}</p>
                <p class="mt-2 max-w-md text-sm leading-relaxed text-powder-200">
                    {{ \App\Support\Perusahaan::text('ringkasan') }}
                </p>
                @if (config('perusahaan.legal.nib'))
                    <p class="mt-4 text-xs text-powder-300">NIB {{ config('perusahaan.legal.nib') }}</p>
                @endif
            </div>

            <div>
                <p class="text-sm font-semibold text-white">Halaman</p>
                <ul class="mt-3 space-y-2 text-sm text-powder-200">
                    @foreach (array_slice($menu, 1) as $rute => [$label, $labelPendek])
                        <li><a class="transition hover:text-white" href="{{ route($rute) }}">{{ $label }}</a></li>
                    @endforeach
                </ul>
            </div>

            <div>
                <p class="text-sm font-semibold text-white">Kontak</p>
                <ul class="mt-3 space-y-2 text-sm text-powder-200">
                    <li>{{ config('perusahaan.kontak.telepon') }}</li>
                    <li>
                        <a class="transition hover:text-white" href="mailto:{{ config('perusahaan.kontak.email') }}">
                            {{ config('perusahaan.kontak.email') }}
                        </a>
                    </li>
                    <li class="leading-relaxed">{{ config('perusahaan.kontak.alamat') }}</li>
                </ul>
            </div>
        </div>

        <div class="border-t border-white/10">
            <div class="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-6 text-xs text-powder-300 sm:flex-row sm:justify-between">
                <p>&copy; {{ date('Y') }} {{ config('perusahaan.nama') }}. Seluruh hak cipta dilindungi.</p>
                {{--
                    Kebijakan Privasi is required under UU PDP 27/2022 before the
                    system is used by real users. Not written yet — the link is
                    deliberately absent rather than pointing at a 404.
                --}}
                <p>Harga grosir hanya untuk pelanggan terdaftar.</p>
            </div>
        </div>
    </footer>

</body>
</html>
