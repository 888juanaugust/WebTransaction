@extends('layouts.publik')

@section('judul', 'Mitra')
@section('deskripsi', 'Perusahaan yang bekerja sama dengan ' . config('perusahaan.nama') . '.')

@section('konten')

    <section class="border-b border-slate-100 bg-brand-50">
        <div class="mx-auto max-w-6xl px-4 py-16">
            <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">Mitra Kerja Sama</h1>
            <p class="mt-4 max-w-2xl text-lg text-slate-600">
                Perusahaan yang bekerja sama dengan kami dalam pasokan, distribusi, dan pengiriman.
            </p>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-16">
        <div class="grid gap-6 md:grid-cols-2">
            @forelse (\App\Support\Perusahaan::records('mitra') as $mitra)
                <article class="rounded-xl border border-slate-200 p-7 transition hover:border-brand-200">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">{{ $mitra['nama'] }}</h2>
                            <p class="mt-1 text-sm font-medium text-brand-600">{{ $mitra['bidang'] }}</p>
                        </div>

                        @if (! empty($mitra['sejak']))
                            <span class="shrink-0 rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700">
                                Sejak {{ $mitra['sejak'] }}
                            </span>
                        @endif
                    </div>

                    <p class="mt-4 leading-relaxed text-slate-600">{{ $mitra['deskripsi'] }}</p>

                    @if (! empty($mitra['negara']))
                        <p class="mt-4 text-sm text-slate-500">{{ $mitra['negara'] }}</p>
                    @endif
                </article>
            @empty
                <p class="text-slate-600">Belum ada mitra yang ditampilkan.</p>
            @endforelse
        </div>

        <div class="mt-14 rounded-xl border border-slate-200 bg-slate-50 p-8">
            <h2 class="text-lg font-semibold text-slate-900">Tertarik bekerja sama?</h2>
            <p class="mt-2 max-w-2xl leading-relaxed text-slate-600">
                Kami terbuka untuk kerja sama pasokan, distribusi regional, maupun logistik.
                Silakan hubungi kami untuk membicarakan peluang kerja sama.
            </p>
            <a href="{{ route('publik.kontak') }}"
               class="mt-6 inline-block rounded-md bg-brand-600 px-6 py-3 text-sm font-semibold text-white
                      transition hover:bg-brand-700">
                Hubungi kami
            </a>
        </div>
    </section>

@endsection
