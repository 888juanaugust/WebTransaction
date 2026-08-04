{{--
    Public site layout.

    Open and indexed — this is the only surface search engines see. No prices
    appear anywhere on it: public price display is out of scope for v1, and
    wholesale pricing is per-customer by definition.

    Bilingual by page, not by app locale. The home page is English; every other
    page is Bahasa Indonesia. Pages set `$lang` and the chrome follows, so
    `<html lang>` is honest for search engines and screen readers on each one.
    The app locale itself stays `id` throughout — switching it per route would
    also flip Filament, dates and validation messages.
--}}
@php
    $lang = $lang ?? 'id';

    // Chrome strings. A full @php block rather than the @php(...) shorthand:
    // the shorthand parses its argument as a directive expression and chokes
    // on an arrow function.
    $t = fn (string $id, string $en) => $lang === 'en' ? $en : $id;
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- The shopfront is white by design; see color-scheme in app.css. --}}
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#1d4ed8">

    <title>@yield('judul', config('perusahaan.nama')) — {{ config('perusahaan.nama') }}</title>
    <meta name="description" content="@yield('deskripsi', \App\Support\Perusahaan::text('ringkasan', $lang))">

    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:site_name" content="{{ config('perusahaan.nama') }}">
    <meta property="og:title" content="@yield('judul', config('perusahaan.nama'))">
    <meta property="og:description" content="@yield('deskripsi', \App\Support\Perusahaan::text('ringkasan', $lang))">
    <meta property="og:locale" content="{{ $lang === 'en' ? 'en_US' : 'id_ID' }}">
    <meta property="og:type" content="website">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white text-slate-800 antialiased flex flex-col">

    <a href="#konten" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50
        focus:rounded-md focus:bg-brand-600 focus:px-4 focus:py-2 focus:text-white">
        {{ $t('Lompat ke konten', 'Skip to content') }}
    </a>

    @php
        // Labels shown in the chrome. The destinations are the same URLs in
        // either language — only this page's wording changes.
        $menu = [
            'publik.beranda' => [$t('Beranda', 'Home'), $t('Beranda', 'Home')],
            'publik.tentang' => [$t('Tentang Kami', 'About Us'), $t('Tentang', 'About')],
            'publik.mitra' => [$t('Mitra', 'Partners'), $t('Mitra', 'Partners')],
            'publik.rencana' => [$t('Rencana Pengembangan', 'Roadmap'), $t('Rencana', 'Roadmap')],
            'publik.kontak' => [$t('Kontak', 'Contact'), $t('Kontak', 'Contact')],
        ];
    @endphp

    <header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur">
        <nav class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4"
             aria-label="{{ $t('Utama', 'Main') }}">
            <a href="{{ route('publik.beranda') }}" class="text-lg font-bold tracking-tight text-brand-600">
                {{ config('perusahaan.nama_singkat') }}
            </a>

            <div class="hidden items-center gap-1 md:flex">
                @foreach ($menu as $rute => [$label, $labelPendek])
                    <a href="{{ route($rute) }}"
                       @class([
                           'rounded-md px-3 py-2 text-sm font-medium transition',
                           'bg-brand-50 text-brand-700' => request()->routeIs($rute),
                           'text-slate-600 hover:bg-slate-50 hover:text-brand-700' => ! request()->routeIs($rute),
                       ])
                       @if (request()->routeIs($rute)) aria-current="page" @endif>
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <a href="{{ route('masuk') }}"
               class="rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm
                      transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-600
                      focus:ring-offset-2">
                {{ $t('Masuk', 'Sign in') }}
            </a>
        </nav>

        {{-- Mobile nav: the desktop row would wrap badly on a phone. --}}
        <div class="flex gap-1 overflow-x-auto border-t border-slate-100 px-4 py-2 md:hidden">
            @foreach ($menu as $rute => [$label, $labelPendek])
                <a href="{{ route($rute) }}"
                   @class([
                       'shrink-0 rounded-md px-3 py-1.5 text-sm font-medium',
                       'bg-brand-50 text-brand-700' => request()->routeIs($rute),
                       'text-slate-600' => ! request()->routeIs($rute),
                   ])>{{ $labelPendek }}</a>
            @endforeach
        </div>
    </header>

    <main id="konten" class="flex-1">
        @yield('konten')
    </main>

    <footer class="mt-20 border-t border-slate-200 bg-slate-50">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2">
                <p class="text-lg font-bold text-brand-600">{{ config('perusahaan.nama') }}</p>
                <p class="mt-2 max-w-md text-sm leading-relaxed text-slate-600">
                    {{ \App\Support\Perusahaan::text('ringkasan', $lang) }}
                </p>
                @if (config('perusahaan.legal.nib'))
                    <p class="mt-4 text-xs text-slate-500">NIB {{ config('perusahaan.legal.nib') }}</p>
                @endif
            </div>

            <div>
                <p class="text-sm font-semibold text-slate-900">{{ $t('Halaman', 'Pages') }}</p>
                <ul class="mt-3 space-y-2 text-sm text-slate-600">
                    @foreach (array_slice($menu, 1) as $rute => [$label, $labelPendek])
                        <li><a class="hover:text-brand-700" href="{{ route($rute) }}">{{ $label }}</a></li>
                    @endforeach
                </ul>
            </div>

            <div>
                <p class="text-sm font-semibold text-slate-900">{{ $t('Kontak', 'Contact') }}</p>
                <ul class="mt-3 space-y-2 text-sm text-slate-600">
                    <li>{{ config('perusahaan.kontak.telepon') }}</li>
                    <li>
                        <a class="hover:text-brand-700" href="mailto:{{ config('perusahaan.kontak.email') }}">
                            {{ config('perusahaan.kontak.email') }}
                        </a>
                    </li>
                    <li class="leading-relaxed">{{ config('perusahaan.kontak.alamat') }}</li>
                </ul>
            </div>
        </div>

        <div class="border-t border-slate-200">
            <div class="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-6 text-xs text-slate-500 sm:flex-row sm:justify-between">
                <p>
                    &copy; {{ date('Y') }} {{ config('perusahaan.nama') }}.
                    {{ $t('Seluruh hak cipta dilindungi.', 'All rights reserved.') }}
                </p>
                {{--
                    Kebijakan Privasi is required under UU PDP 27/2022 before the
                    system is used by real users. Not written yet — the link is
                    deliberately absent rather than pointing at a 404.
                --}}
                <p>{{ $t('Harga grosir hanya untuk pelanggan terdaftar.', 'Wholesale pricing is for registered customers only.') }}</p>
            </div>
        </div>
    </footer>

</body>
</html>
