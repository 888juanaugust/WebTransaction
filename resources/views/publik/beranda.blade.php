@extends('layouts.publik')

@section('judul', 'Beranda')
@section('deskripsi', \App\Support\Perusahaan::text('ringkasan'))

@php
    use App\Support\Perusahaan;
@endphp

@section('konten')

    {{-- Hero: white with a blue wash, not a solid block. --}}
    <section class="border-b border-slate-100 bg-gradient-to-b from-brand-50 to-white">
        <div class="mx-auto max-w-6xl px-4 py-20 sm:py-28">
            <p class="text-sm font-semibold uppercase tracking-wide text-brand-600">
                {{ Perusahaan::text('tagline') }}
            </p>

            <h1 class="mt-4 max-w-3xl text-4xl font-bold leading-tight tracking-tight text-slate-900 sm:text-5xl">
                {{ config('perusahaan.nama') }}
            </h1>

            <p class="mt-6 max-w-2xl text-lg leading-relaxed text-slate-600">
                {{ Perusahaan::text('ringkasan') }}
            </p>

            <div class="mt-10 flex flex-wrap gap-3">
                <a href="{{ route('masuk') }}"
                   class="rounded-md bg-brand-600 px-6 py-3 text-sm font-semibold text-white shadow-sm
                          transition hover:bg-brand-700">
                    Masuk ke akun Anda
                </a>
                <a href="{{ route('publik.kontak') }}"
                   class="rounded-md border border-slate-300 bg-white px-6 py-3 text-sm font-semibold
                          text-slate-700 transition hover:border-brand-600 hover:text-brand-700">
                    Hubungi kami
                </a>
            </div>

            {{-- Sets expectations immediately: this is not a retail shop. --}}
            <p class="mt-8 text-sm text-slate-500">
                Kami memasok <strong class="font-semibold text-slate-700">bengkel, toko sparepart
                dan distributor</strong>. Harga grosir hanya untuk pelanggan terdaftar.
            </p>
        </div>
    </section>

    {{-- Categories, straight after the hero: what we actually sell. --}}
    <section class="mx-auto max-w-6xl px-4 py-20">
        <h2 class="text-2xl font-bold tracking-tight text-slate-900">Kategori produk</h2>

        <div class="mt-8 grid gap-6 sm:grid-cols-2">
            @foreach (Perusahaan::records('kategori') as $kategori)
                <div class="rounded-xl border border-slate-200 p-6 transition hover:border-brand-200 hover:bg-brand-50/40">
                    <h3 class="font-semibold tracking-wide text-brand-700">{{ $kategori['nama'] }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $kategori['deskripsi'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- No SKU list and no prices: wholesale pricing is per customer. --}}
        <p class="mt-8 text-sm text-slate-500">
            Daftar produk lengkap beserta harga tersedia untuk pelanggan terdaftar setelah masuk.
        </p>
    </section>

    {{-- Brands --}}
    <section class="border-y border-slate-100 bg-slate-50">
        <div class="mx-auto max-w-6xl px-4 py-16">
            <h2 class="text-2xl font-bold tracking-tight text-slate-900">Merk yang kami bawa</h2>
            <p class="mt-2 text-slate-600">Tersedia untuk pengiriman ke seluruh Indonesia.</p>

            <ul class="mt-8 flex flex-wrap gap-3">
                @foreach (config('perusahaan.merk') as $merk)
                    <li class="rounded-lg border border-slate-200 bg-white px-5 py-3 text-sm font-semibold
                               tracking-wide text-slate-700">
                        {{ $merk }}
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- About --}}
    <section class="mx-auto max-w-6xl px-4 py-20">
        <h2 class="text-2xl font-bold tracking-tight text-slate-900">Tentang perusahaan kami</h2>

        <div class="mt-6 max-w-3xl space-y-4">
            @foreach (array_slice(Perusahaan::list('profil'), 0, 2) as $paragraf)
                <p class="leading-relaxed text-slate-600">{{ $paragraf }}</p>
            @endforeach
        </div>

        <a href="{{ route('publik.tentang') }}"
           class="mt-6 inline-block text-sm font-semibold text-brand-600 hover:text-brand-700">
            Selengkapnya tentang kami →
        </a>
    </section>

    {{-- Partners --}}
    <section class="border-t border-slate-100 bg-slate-50">
        <div class="mx-auto max-w-6xl px-4 py-16">
            <h2 class="text-2xl font-bold tracking-tight text-slate-900">Mitra usaha patungan</h2>
            <p class="mt-2 max-w-2xl text-slate-600">
                Kami bekerja sama dengan sejumlah perusahaan agar pasokan tetap stabil
                dan jangkauan pengiriman tetap luas.
            </p>

            <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (Perusahaan::records('mitra') as $mitra)
                    <div class="rounded-xl border border-slate-200 bg-white p-6">
                        <h3 class="font-semibold text-slate-900">{{ $mitra['nama'] }}</h3>
                        <p class="mt-1 text-sm text-brand-600">{{ $mitra['bidang'] }}</p>
                        <p class="mt-3 text-sm leading-relaxed text-slate-600">{{ $mitra['deskripsi'] }}</p>
                    </div>
                @endforeach
            </div>

            <a href="{{ route('publik.mitra') }}"
               class="mt-8 inline-block text-sm font-semibold text-brand-600 hover:text-brand-700">
                Lihat semua mitra →
            </a>
        </div>
    </section>

    {{-- Call to action --}}
    <section class="mx-auto max-w-6xl px-4 py-20">
        <div class="rounded-2xl bg-brand-600 px-8 py-12 text-center">
            <h2 class="text-2xl font-bold text-white">Sudah menjadi pelanggan?</h2>
            <p class="mx-auto mt-3 max-w-xl text-brand-100">
                Masuk untuk melihat harga Anda, sisa limit kredit, tagihan dan riwayat pesanan.
            </p>
            <a href="{{ route('masuk') }}"
               class="mt-8 inline-block rounded-md bg-white px-6 py-3 text-sm font-semibold text-brand-700
                      transition hover:bg-brand-50">
                Masuk ke akun Anda
            </a>
        </div>
    </section>

@endsection
