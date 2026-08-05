@extends('layouts.publik')

@section('judul', 'Beranda')
@section('deskripsi', \App\Support\Perusahaan::text('ringkasan'))

@php
    use App\Support\Perusahaan;
@endphp

@section('konten')

    {{--
        Hero. Navy ground with the coral accent on top — the shopfront is
        allowed to spend coral freely, unlike the panels where it has to keep
        meaning "something is wrong".
    --}}
    <section class="relative overflow-hidden bg-brand-600">
        {{-- Two soft washes so the navy is not a flat rectangle. --}}
        <div class="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-powder-300/20 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-32 left-1/3 h-80 w-80 rounded-full bg-accent-600/20 blur-3xl"></div>

        <div class="relative mx-auto max-w-6xl px-4 py-20 sm:py-28">
            <p class="inline-block rounded-full bg-accent-600 px-4 py-1.5 text-sm font-semibold text-white">
                {{ Perusahaan::text('tagline') }}
            </p>

            <h1 class="mt-6 max-w-3xl text-4xl font-bold leading-tight tracking-tight text-white sm:text-5xl">
                {{ config('perusahaan.nama') }}
            </h1>

            <p class="mt-6 max-w-2xl text-lg leading-relaxed text-powder-200">
                {{ Perusahaan::text('ringkasan') }}
            </p>

            <div class="mt-10 flex flex-wrap gap-3">
                <a href="{{ route('masuk') }}"
                   class="rounded-md bg-accent-600 px-6 py-3 text-sm font-semibold text-white shadow-lg
                          transition hover:bg-accent-700">
                    Masuk ke akun Anda
                </a>
                <a href="{{ route('publik.kontak') }}"
                   class="rounded-md border border-white/30 px-6 py-3 text-sm font-semibold text-white
                          transition hover:bg-white/10">
                    Hubungi kami
                </a>
            </div>

            {{-- Sets expectations immediately: this is not a retail shop. --}}
            <p class="mt-8 text-sm text-powder-300">
                Kami memasok <strong class="font-semibold text-white">bengkel, toko sparepart
                dan distributor</strong>. Harga grosir hanya untuk pelanggan terdaftar.
            </p>
        </div>
    </section>

    {{-- Categories, straight after the hero: what we actually sell. --}}
    <section class="mx-auto max-w-6xl px-4 py-20">
        <h2 class="text-2xl font-bold tracking-tight text-brand-900">Kategori produk</h2>

        <div class="mt-8 grid gap-6 sm:grid-cols-2">
            @foreach (Perusahaan::records('kategori') as $kategori)
                <div class="rounded-xl border-l-4 border-accent-600 bg-white p-6 shadow-sm ring-1 ring-powder-200
                            transition hover:shadow-md">
                    <h3 class="font-semibold tracking-wide text-brand-700">{{ $kategori['nama'] }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-brand-900/70">{{ $kategori['deskripsi'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- No SKU list and no prices: wholesale pricing is per customer. --}}
        <p class="mt-8 text-sm text-brand-900/60">
            Daftar produk lengkap beserta harga tersedia untuk pelanggan terdaftar setelah masuk.
        </p>
    </section>

    {{-- Brands --}}
    <section class="bg-powder-100">
        <div class="mx-auto max-w-6xl px-4 py-16">
            <h2 class="text-2xl font-bold tracking-tight text-brand-900">Merk yang kami bawa</h2>
            <p class="mt-2 text-brand-900/70">Tersedia untuk pengiriman ke seluruh Indonesia.</p>

            <ul class="mt-8 flex flex-wrap gap-3">
                @foreach (config('perusahaan.merk') as $merk)
                    <li class="rounded-lg bg-brand-600 px-5 py-3 text-sm font-semibold tracking-wide text-white">
                        {{ $merk }}
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- About --}}
    <section class="mx-auto max-w-6xl px-4 py-20">
        <h2 class="text-2xl font-bold tracking-tight text-brand-900">Tentang perusahaan kami</h2>

        <div class="mt-6 max-w-3xl space-y-4">
            @foreach (array_slice(Perusahaan::list('profil'), 0, 2) as $paragraf)
                <p class="leading-relaxed text-brand-900/75">{{ $paragraf }}</p>
            @endforeach
        </div>

        <a href="{{ route('publik.tentang') }}"
           class="mt-6 inline-block text-sm font-semibold text-accent-600 hover:text-accent-700">
            Selengkapnya tentang kami →
        </a>
    </section>

    {{-- Partners --}}
    <section class="bg-powder-100">
        <div class="mx-auto max-w-6xl px-4 py-16">
            <h2 class="text-2xl font-bold tracking-tight text-brand-900">Mitra usaha patungan</h2>
            <p class="mt-2 max-w-2xl text-brand-900/70">
                Kami bekerja sama dengan sejumlah perusahaan agar pasokan tetap stabil
                dan jangkauan pengiriman tetap luas.
            </p>

            <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (Perusahaan::records('mitra') as $mitra)
                    <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-powder-200">
                        <h3 class="font-semibold text-brand-900">{{ $mitra['nama'] }}</h3>
                        <p class="mt-1 text-sm font-medium text-accent-600">{{ $mitra['bidang'] }}</p>
                        <p class="mt-3 text-sm leading-relaxed text-brand-900/70">{{ $mitra['deskripsi'] }}</p>
                    </div>
                @endforeach
            </div>

            <a href="{{ route('publik.mitra') }}"
               class="mt-8 inline-block text-sm font-semibold text-accent-600 hover:text-accent-700">
                Lihat semua mitra →
            </a>
        </div>
    </section>

    {{-- Call to action --}}
    <section class="mx-auto max-w-6xl px-4 py-20">
        <div class="rounded-2xl bg-accent-600 px-8 py-12 text-center shadow-lg">
            <h2 class="text-2xl font-bold text-white">Sudah menjadi pelanggan?</h2>
            <p class="mx-auto mt-3 max-w-xl text-white/90">
                Masuk untuk melihat harga Anda, sisa limit kredit, tagihan dan riwayat pesanan.
            </p>
            <a href="{{ route('masuk') }}"
               class="mt-8 inline-block rounded-md bg-white px-6 py-3 text-sm font-semibold text-accent-700
                      transition hover:bg-accent-50">
                Masuk ke akun Anda
            </a>
        </div>
    </section>

@endsection
