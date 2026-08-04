{{--
    Public site layout.

    Open and indexed — this is the only surface search engines see. No prices
    appear anywhere on it: public price display is out of scope for v1, and
    wholesale pricing is per-customer by definition.
--}}
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('judul', config('perusahaan.nama')) — {{ config('perusahaan.nama') }}</title>
    <meta name="description" content="@yield('deskripsi', config('perusahaan.ringkasan'))">

    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:site_name" content="{{ config('perusahaan.nama') }}">
    <meta property="og:title" content="@yield('judul', config('perusahaan.nama'))">
    <meta property="og:description" content="@yield('deskripsi', config('perusahaan.ringkasan'))">
    <meta property="og:type" content="website">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white text-slate-800 antialiased flex flex-col">

    <a href="#konten" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50
        focus:rounded-md focus:bg-brand-600 focus:px-4 focus:py-2 focus:text-white">
        Lompat ke konten
    </a>

    <header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur">
        <nav class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4" aria-label="Utama">
            <a href="{{ route('publik.beranda') }}" class="text-lg font-bold tracking-tight text-brand-600">
                {{ config('perusahaan.nama_singkat') }}
            </a>

            <div class="hidden items-center gap-1 md:flex">
                @foreach ([
                    'publik.beranda' => 'Beranda',
                    'publik.tentang' => 'Tentang Kami',
                    'publik.mitra' => 'Mitra',
                    'publik.rencana' => 'Rencana Pengembangan',
                    'publik.kontak' => 'Kontak',
                ] as $rute => $label)
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
                Masuk
            </a>
        </nav>

        {{-- Mobile nav: the desktop row would wrap badly on a phone. --}}
        <div class="flex gap-1 overflow-x-auto border-t border-slate-100 px-4 py-2 md:hidden">
            @foreach ([
                'publik.beranda' => 'Beranda',
                'publik.tentang' => 'Tentang',
                'publik.mitra' => 'Mitra',
                'publik.rencana' => 'Rencana',
                'publik.kontak' => 'Kontak',
            ] as $rute => $label)
                <a href="{{ route($rute) }}"
                   @class([
                       'shrink-0 rounded-md px-3 py-1.5 text-sm font-medium',
                       'bg-brand-50 text-brand-700' => request()->routeIs($rute),
                       'text-slate-600' => ! request()->routeIs($rute),
                   ])>{{ $label }}</a>
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
                    {{ config('perusahaan.ringkasan') }}
                </p>
                @if (config('perusahaan.legal.nib'))
                    <p class="mt-4 text-xs text-slate-500">NIB {{ config('perusahaan.legal.nib') }}</p>
                @endif
            </div>

            <div>
                <p class="text-sm font-semibold text-slate-900">Halaman</p>
                <ul class="mt-3 space-y-2 text-sm text-slate-600">
                    <li><a class="hover:text-brand-700" href="{{ route('publik.tentang') }}">Tentang Kami</a></li>
                    <li><a class="hover:text-brand-700" href="{{ route('publik.mitra') }}">Mitra</a></li>
                    <li><a class="hover:text-brand-700" href="{{ route('publik.rencana') }}">Rencana Pengembangan</a></li>
                    <li><a class="hover:text-brand-700" href="{{ route('publik.kontak') }}">Kontak</a></li>
                </ul>
            </div>

            <div>
                <p class="text-sm font-semibold text-slate-900">Kontak</p>
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
