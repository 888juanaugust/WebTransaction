@extends('layouts.publik')

@section('judul', 'Mitra')
@section('deskripsi', 'Perusahaan yang bekerja sama dengan ' . config('perusahaan.nama') . '.')

@section('konten')

    @include('publik.partials.hero-halaman', [
        'kicker' => 'Kerja sama',
        'judul' => 'Mitra Kerja Sama',
        'lede' => 'Perusahaan yang bekerja sama dengan kami dalam pasokan, distribusi, dan pengiriman.',
    ])

    <section class="mx-auto max-w-6xl px-4 py-16">
        <div class="grid gap-6 md:grid-cols-2">
            @forelse (\App\Support\Perusahaan::records('mitra') as $mitra)
                <article class="rounded-2xl bg-white p-7 shadow-sm ring-1 ring-slate-900/5 transition
                                hover:-translate-y-0.5 hover:shadow-md">
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

        <div class="mt-14 rounded-2xl bg-slate-50 p-8 ring-1 ring-slate-900/5">
            <h2 class="text-lg font-semibold text-slate-900">Tertarik bekerja sama?</h2>
            <p class="mt-2 max-w-2xl leading-relaxed text-slate-600">
                Kami terbuka untuk kerja sama pasokan, distribusi regional, maupun logistik.
                Silakan hubungi kami untuk membicarakan peluang kerja sama.
            </p>
            <a href="{{ route('publik.kontak') }}"
               class="mt-6 inline-block rounded-lg bg-brand-600 px-6 py-3 text-sm font-semibold text-white
                      shadow-sm transition hover:bg-brand-500">
                Hubungi kami
            </a>
        </div>
    </section>

@endsection
