@extends('layouts.publik', ['lang' => 'en'])

{{--
    The home page is the one English surface. Every page it links to is Bahasa
    Indonesia — that is deliberate, so the nav labels here are English while
    their destinations are not.
--}}

@section('judul', 'Home')
@section('deskripsi', \App\Support\Perusahaan::text('ringkasan', 'en'))

@php
    use App\Support\Perusahaan;

    $lang = 'en';
@endphp

@section('konten')

    {{-- Hero --}}
    <section class="border-b border-slate-100 bg-gradient-to-b from-brand-50 to-white">
        <div class="mx-auto max-w-6xl px-4 py-20 sm:py-28">
            <p class="text-sm font-semibold uppercase tracking-wide text-brand-600">
                {{ Perusahaan::text('tagline', $lang) }}
            </p>

            <h1 class="mt-4 max-w-3xl text-4xl font-bold leading-tight tracking-tight text-slate-900 sm:text-5xl">
                {{ config('perusahaan.nama') }}
            </h1>

            <p class="mt-6 max-w-2xl text-lg leading-relaxed text-slate-600">
                {{ Perusahaan::text('ringkasan', $lang) }}
            </p>

            <div class="mt-10 flex flex-wrap gap-3">
                <a href="{{ route('masuk') }}"
                   class="rounded-md bg-brand-600 px-6 py-3 text-sm font-semibold text-white shadow-sm
                          transition hover:bg-brand-700">
                    Sign in to your account
                </a>
                <a href="{{ route('publik.kontak') }}"
                   class="rounded-md border border-slate-300 bg-white px-6 py-3 text-sm font-semibold
                          text-slate-700 transition hover:border-brand-600 hover:text-brand-700">
                    Get in touch
                </a>
            </div>

            {{-- Sets expectations immediately: this is not a retail shop. --}}
            <p class="mt-8 text-sm text-slate-500">
                We supply <strong class="font-semibold text-slate-700">workshops, parts retailers
                and distributors</strong>. Wholesale pricing is available to registered customers only.
            </p>
        </div>
    </section>

    {{-- What we do --}}
    <section class="mx-auto max-w-6xl px-4 py-20">
        <h2 class="text-2xl font-bold tracking-tight text-slate-900">About our company</h2>

        <div class="mt-6 max-w-3xl space-y-4">
            @foreach (array_slice(Perusahaan::list('profil', $lang), 0, 2) as $paragraph)
                <p class="leading-relaxed text-slate-600">{{ $paragraph }}</p>
            @endforeach
        </div>

        <a href="{{ route('publik.tentang') }}"
           class="mt-6 inline-block text-sm font-semibold text-brand-600 hover:text-brand-700">
            More about us →
        </a>
    </section>

    {{-- Brands --}}
    <section class="border-y border-slate-100 bg-slate-50">
        <div class="mx-auto max-w-6xl px-4 py-16">
            <h2 class="text-2xl font-bold tracking-tight text-slate-900">Brands we carry</h2>
            <p class="mt-2 text-slate-600">Available for delivery throughout Indonesia.</p>

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

    {{-- Categories --}}
    <section class="mx-auto max-w-6xl px-4 py-20">
        <h2 class="text-2xl font-bold tracking-tight text-slate-900">Product categories</h2>

        <div class="mt-8 grid gap-6 sm:grid-cols-2">
            @foreach (Perusahaan::records('kategori', $lang) as $kategori)
                <div class="rounded-xl border border-slate-200 p-6 transition hover:border-brand-200 hover:bg-brand-50/40">
                    <h3 class="font-semibold tracking-wide text-brand-700">{{ $kategori['nama'] }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $kategori['deskripsi'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- No SKU list and no prices: wholesale pricing is per customer. --}}
        <p class="mt-8 text-sm text-slate-500">
            The full product list and pricing are available to registered customers after signing in.
        </p>
    </section>

    {{-- Partners --}}
    <section class="border-t border-slate-100 bg-slate-50">
        <div class="mx-auto max-w-6xl px-4 py-16">
            <h2 class="text-2xl font-bold tracking-tight text-slate-900">Joint-venture partners</h2>
            <p class="mt-2 max-w-2xl text-slate-600">
                We work with a number of companies to keep supply steady and delivery wide.
            </p>

            <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (Perusahaan::records('mitra', $lang) as $mitra)
                    <div class="rounded-xl border border-slate-200 bg-white p-6">
                        <h3 class="font-semibold text-slate-900">{{ $mitra['nama'] }}</h3>
                        <p class="mt-1 text-sm text-brand-600">{{ $mitra['bidang'] }}</p>
                        <p class="mt-3 text-sm leading-relaxed text-slate-600">{{ $mitra['deskripsi'] }}</p>
                    </div>
                @endforeach
            </div>

            <a href="{{ route('publik.mitra') }}"
               class="mt-8 inline-block text-sm font-semibold text-brand-600 hover:text-brand-700">
                See all partners →
            </a>
        </div>
    </section>

    {{-- Call to action --}}
    <section class="mx-auto max-w-6xl px-4 py-20">
        <div class="rounded-2xl bg-brand-600 px-8 py-12 text-center">
            <h2 class="text-2xl font-bold text-white">Already a customer?</h2>
            <p class="mx-auto mt-3 max-w-xl text-brand-100">
                Sign in to see your pricing, remaining credit, invoices and order history.
            </p>
            <a href="{{ route('masuk') }}"
               class="mt-8 inline-block rounded-md bg-white px-6 py-3 text-sm font-semibold text-brand-700
                      transition hover:bg-brand-50">
                Sign in to your account
            </a>
        </div>
    </section>

@endsection
