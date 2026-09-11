@extends('layouts.publik')

@section('judul', __('publik.mitra.judul'))
@section('deskripsi', __('publik.mitra.deskripsi', ['nama' => config('perusahaan.nama')]))

@section('konten')

    @include('publik.partials.hero-halaman', [
        'judul' => __('publik.mitra.judul'),
        'lede' => __('publik.mitra.lede'),
    ])

    <section class="mx-auto max-w-6xl px-4 py-16 sm:py-20">
        <ul class="divide-y divide-line border-y border-line">
            @forelse (\App\Support\Perusahaan::records('mitra') as $mitra)
                <li class="grid gap-3 py-7 md:grid-cols-[1fr_200px_120px] md:items-start md:gap-8">
                    <div>
                        <h2 class="text-lg font-semibold tracking-tight text-ink">{{ $mitra['nama'] }}</h2>
                        <p class="mt-2 max-w-xl leading-relaxed text-ink-muted">{{ $mitra['deskripsi'] }}</p>
                    </div>
                    <div class="text-sm text-ink-muted">
                        <p class="font-medium text-ink">{{ $mitra['bidang'] }}</p>
                        @if (! empty($mitra['negara']))
                            <p class="mt-0.5">{{ $mitra['negara'] }}</p>
                        @endif
                    </div>
                    @if (! empty($mitra['sejak']))
                        <p class="text-sm text-ink-muted md:text-right">{{ __('publik.mitra.sejak') }} {{ $mitra['sejak'] }}</p>
                    @endif
                </li>
            @empty
                <li class="py-7 text-ink-muted">{{ __('publik.mitra.kosong') }}</li>
            @endforelse
        </ul>

        <div class="mt-14 flex flex-col items-start justify-between gap-6 rounded-panel border border-line bg-white p-7 shadow-card sm:flex-row sm:items-center sm:p-9">
            <div>
                <h2 class="text-xl font-semibold tracking-tight text-ink">{{ __('publik.mitra.ajak_judul') }}</h2>
                <p class="mt-2 max-w-xl leading-relaxed text-ink-muted">
                    {{ __('publik.mitra.ajak_lede') }}
                </p>
            </div>
            <a href="{{ route('publik.kontak') }}"
               class="shrink-0 rounded-btn bg-brand-600 px-5 py-3 text-[15px] font-semibold text-white shadow-btn
                      transition duration-200 hover:bg-brand-500 active:scale-[0.98]">
                {{ __('publik.mitra.hubungi') }}
            </a>
        </div>
    </section>

@endsection
