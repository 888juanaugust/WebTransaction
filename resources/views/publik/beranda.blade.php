@extends('layouts.publik')

@section('judul', 'Beranda')
@section('deskripsi', \App\Support\Perusahaan::text('ringkasan'))

@php
    use App\Support\Perusahaan;

    /*
     * One stroke icon per category, matched on a keyword so a renamed or
     * added category degrades to the neutral box icon instead of breaking.
     * Paths are Heroicons outlines, inlined: the page must not fetch assets.
     */
    $ikonKategori = function (string $nama): string {
        $peta = [
            'HYDRAULIC' => 'M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z',
            'SUSPENSION' => 'M3 7.5 7.5 3m0 0L12 7.5M7.5 3v13.5m13.5 0L16.5 21m0 0L12 16.5m4.5 4.5V7.5',
            'ELECTRIC' => 'm3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z',
            'BEARING' => 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
        ];

        foreach ($peta as $kunci => $path) {
            if (str_contains(strtoupper($nama), $kunci)) {
                return $path;
            }
        }

        return 'm21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9';
    };
@endphp

@section('konten')

    {{--
        Hero: the shopfront opens on the brand. Deep navy, white type, and
        one red line under the eyebrow — the same accent grammar every
        section below repeats. The soft radial glows keep the block from
        reading as a flat slab without adding a single image request.
    --}}
    <section class="relative overflow-hidden bg-gradient-to-br from-brand-900 via-brand-800 to-brand-700">
        <div class="pointer-events-none absolute inset-0" aria-hidden="true">
            <div class="absolute -top-32 right-0 h-96 w-96 rounded-full bg-brand-500/20 blur-3xl"></div>
            <div class="absolute -bottom-40 -left-20 h-96 w-96 rounded-full bg-brand-600/40 blur-3xl"></div>
        </div>

        <div class="relative mx-auto max-w-6xl px-4 pb-16 pt-20 sm:pb-20 sm:pt-28">
            <p class="flex items-center gap-3 text-xs font-semibold uppercase tracking-[0.2em] text-brand-200">
                <span class="h-px w-8 bg-accent-600" aria-hidden="true"></span>
                {{ Perusahaan::text('tagline') }}
            </p>

            <h1 class="mt-5 max-w-3xl text-4xl font-bold leading-[1.1] tracking-tight text-white sm:text-6xl">
                {{ config('perusahaan.nama') }}
            </h1>

            <p class="mt-6 max-w-2xl text-lg leading-relaxed text-brand-100">
                {{ Perusahaan::text('ringkasan') }}
            </p>

            <div class="mt-10 flex flex-wrap gap-3">
                <a href="{{ route('masuk') }}"
                   class="rounded-lg bg-white px-6 py-3 text-sm font-semibold text-brand-700 shadow-sm
                          transition hover:bg-brand-50">
                    Masuk ke akun Anda
                </a>
                <a href="{{ route('publik.kontak') }}"
                   class="rounded-lg border border-white/30 px-6 py-3 text-sm font-semibold text-white
                          transition hover:border-white/60 hover:bg-white/10">
                    Hubungi kami
                </a>
            </div>

            {{-- Sets expectations immediately: this is not a retail shop. --}}
            <p class="mt-8 max-w-2xl text-sm text-brand-200">
                Kami memasok <strong class="font-semibold text-white">bengkel, toko sparepart dan
                distributor</strong> — bukan pembeli eceran. Harga grosir hanya untuk pelanggan terdaftar.
            </p>

            {{-- The facts a buyer sizes a supplier up by, straight from config. --}}
            <dl class="mt-14 grid grid-cols-2 gap-px overflow-hidden rounded-xl bg-white/10 ring-1 ring-white/15 sm:grid-cols-4">
                @foreach ([
                    [count(Perusahaan::records('kategori')), 'Kategori produk'],
                    [count(config('perusahaan.merk')), 'Merk dipasok'],
                    [config('perusahaan.kontak.kota'), 'Gudang utama'],
                    ['30 hari', 'Termin kredit standar'],
                ] as [$angka, $label])
                    <div class="bg-brand-900/40 px-5 py-4 backdrop-blur-sm">
                        <dt class="text-xs text-brand-200">{{ $label }}</dt>
                        <dd class="mt-1 text-2xl font-bold text-white">{{ $angka }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    {{-- Categories, straight after the hero: what we actually sell. --}}
    <section class="mx-auto max-w-6xl px-4 py-20 sm:py-24">
        @include('publik.partials.kicker', [
            'kicker' => 'Apa yang kami jual',
            'judul' => 'Kategori produk',
            'lede' => 'Empat kategori yang paling sering dibutuhkan bengkel, dijaga ketersediaannya.',
        ])

        <div class="mt-10 grid gap-5 sm:grid-cols-2">
            @foreach (Perusahaan::records('kategori') as $kategori)
                <div class="group rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-900/5 transition
                            hover:-translate-y-0.5 hover:shadow-md">
                    <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50
                                 text-brand-600 transition group-hover:bg-brand-600 group-hover:text-white">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                             stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $ikonKategori($kategori['nama']) }}"/>
                        </svg>
                    </span>
                    <h3 class="mt-4 font-semibold tracking-wide text-slate-900">{{ $kategori['nama'] }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $kategori['deskripsi'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- No SKU list and no prices: wholesale pricing is per customer. --}}
        <p class="mt-8 text-sm text-slate-500">
            Daftar produk lengkap beserta harga tersedia untuk pelanggan terdaftar setelah masuk.
        </p>
    </section>

    {{-- Brands: a quiet logo wall — the names are the content. --}}
    <section class="border-y border-slate-100 bg-slate-50/70">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:py-20">
            @include('publik.partials.kicker', [
                'kicker' => 'Merk',
                'judul' => 'Merk yang kami bawa',
                'lede' => 'Tersedia untuk pengiriman ke seluruh Indonesia.',
            ])

            <ul class="mt-10 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
                @foreach (config('perusahaan.merk') as $merk)
                    <li class="flex items-center justify-center rounded-xl bg-white px-4 py-5 text-sm
                               font-bold uppercase tracking-[0.15em] text-brand-700 shadow-sm ring-1 ring-slate-900/5">
                        {{ $merk }}
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- How an account opens: the three steps between "interested" and "ordering". --}}
    <section class="mx-auto max-w-6xl px-4 py-20 sm:py-24">
        @include('publik.partials.kicker', [
            'kicker' => 'Cara kerja',
            'judul' => 'Menjadi pelanggan dalam tiga langkah',
        ])

        <ol class="mt-10 grid gap-5 sm:grid-cols-3">
            @foreach ([
                ['Hubungi kami', 'Kirim nama usaha, alamat dan NPWP lewat WhatsApp atau email. Akun grosir dibuka setelah verifikasi data usaha.'],
                ['Sepakati harga dan limit', 'Tim kami menetapkan tingkat harga dan limit kredit sesuai skala usaha Anda, dengan termin pembayaran yang jelas.'],
                ['Pesan lewat portal', 'Akses katalog dengan harga Anda, pesan ulang order rutin dalam satu klik, dan pantau tagihan kapan saja.'],
            ] as $i => [$judul, $isi])
                <li class="relative rounded-2xl bg-white p-6 pt-8 shadow-sm ring-1 ring-slate-900/5">
                    <span class="absolute -top-4 left-6 flex h-9 w-9 items-center justify-center rounded-xl
                                 bg-brand-600 text-sm font-bold text-white shadow-sm">{{ $i + 1 }}</span>
                    <h3 class="font-semibold text-slate-900">{{ $judul }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $isi }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- About --}}
    <section class="border-y border-slate-100 bg-slate-50/70">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:py-20">
            <div class="grid items-start gap-10 lg:grid-cols-2">
                <div>
                    @include('publik.partials.kicker', [
                        'kicker' => 'Perusahaan',
                        'judul' => 'Tentang perusahaan kami',
                    ])

                    <div class="mt-6 space-y-4">
                        @foreach (array_slice(Perusahaan::list('profil'), 0, 2) as $paragraf)
                            <p class="leading-relaxed text-slate-600">{{ $paragraf }}</p>
                        @endforeach
                    </div>

                    <a href="{{ route('publik.tentang') }}"
                       class="mt-6 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600
                              transition hover:text-brand-700 hover:gap-2.5">
                        Selengkapnya tentang kami
                        <span aria-hidden="true">&rarr;</span>
                    </a>
                </div>

                {{-- Partners, condensed: the full list has its own page. --}}
                <div class="grid gap-4">
                    @foreach (Perusahaan::records('mitra') as $mitra)
                        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-900/5">
                            <div class="flex items-baseline justify-between gap-4">
                                <h3 class="font-semibold text-slate-900">{{ $mitra['nama'] }}</h3>
                                <span class="shrink-0 text-xs font-medium uppercase tracking-wide text-brand-600">
                                    {{ $mitra['bidang'] }}
                                </span>
                            </div>
                            <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $mitra['deskripsi'] }}</p>
                        </div>
                    @endforeach

                    <a href="{{ route('publik.mitra') }}"
                       class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600
                              transition hover:text-brand-700 hover:gap-2.5">
                        Lihat semua mitra
                        <span aria-hidden="true">&rarr;</span>
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- Call to action --}}
    <section class="mx-auto max-w-6xl px-4 py-20 sm:py-24">
        <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-900 via-brand-800 to-brand-700
                    px-8 py-14 text-center shadow-lg">
            <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                <div class="absolute -top-24 left-1/2 h-64 w-[36rem] -translate-x-1/2 rounded-full bg-brand-500/25 blur-3xl"></div>
            </div>
            <div class="relative">
                <h2 class="text-2xl font-bold text-white sm:text-3xl">Sudah menjadi pelanggan?</h2>
                <p class="mx-auto mt-3 max-w-xl text-brand-100">
                    Masuk untuk melihat harga Anda, sisa limit kredit, tagihan dan riwayat pesanan.
                </p>
                <a href="{{ route('masuk') }}"
                   class="mt-8 inline-block rounded-lg bg-white px-6 py-3 text-sm font-semibold text-brand-700
                          shadow-sm transition hover:bg-brand-50">
                    Masuk ke akun Anda
                </a>
            </div>
        </div>
    </section>

@endsection
