@extends('layouts.publik')

@section('judul', __('publik.tentang.judul'))
@section('deskripsi', __('publik.tentang.deskripsi', ['nama' => config('perusahaan.nama')]))

@section('konten')

    @include('publik.partials.hero-halaman', [
        'judul' => __('publik.tentang.judul'),
        'lede' => \App\Support\Perusahaan::text('tagline'),
    ])

    <section class="mx-auto max-w-6xl px-4 py-16 sm:py-20">
        <div class="grid gap-12 lg:grid-cols-3 lg:gap-16">

            <div class="lg:col-span-2">
                <h2 class="text-2xl font-semibold tracking-tight text-ink">{{ __('publik.tentang.profil') }}</h2>

                <div class="mt-5 max-w-2xl space-y-5">
                    @foreach (\App\Support\Perusahaan::list('profil') as $paragraf)
                        <p class="leading-relaxed text-ink-muted">{{ $paragraf }}</p>
                    @endforeach
                </div>

                <h2 class="mt-16 text-2xl font-semibold tracking-tight text-ink">{{ __('publik.tentang.melayani') }}</h2>
                <ul class="mt-6 divide-y divide-line border-y border-line">
                    @foreach (__('publik.tentang.segmen') as [$judul, $isi])
                        <li class="grid gap-1 py-5 sm:grid-cols-[180px_1fr] sm:gap-6">
                            <h3 class="font-semibold text-ink">{{ $judul }}</h3>
                            <p class="text-[15px] leading-relaxed text-ink-muted">{{ $isi }}</p>
                        </li>
                    @endforeach
                </ul>

                <h2 class="mt-16 text-2xl font-semibold tracking-tight text-ink">{{ __('publik.tentang.cara') }}</h2>
                <ol class="mt-6 space-y-4">
                    @foreach (__('publik.tentang.langkah') as $i => $langkah)
                        <li class="flex gap-4">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[10px]
                                         bg-ground-2 text-sm font-semibold text-brand-600">{{ $i + 1 }}</span>
                            <span class="pt-1 leading-relaxed text-ink-muted">{{ $langkah }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>

            <aside class="lg:col-span-1">
                <div class="rounded-panel border border-line bg-white p-6 shadow-card">
                    <h2 class="font-semibold text-ink">{{ __('publik.tentang.rincian') }}</h2>

                    <dl class="mt-4 divide-y divide-line text-sm">
                        <div class="py-3">
                            <dt class="text-ink-muted">{{ __('publik.tentang.nama') }}</dt>
                            <dd class="mt-0.5 font-medium text-ink">{{ config('perusahaan.nama') }}</dd>
                        </div>
                        <div class="py-3">
                            <dt class="text-ink-muted">{{ __('publik.tentang.bentuk') }}</dt>
                            <dd class="mt-0.5 font-medium text-ink">{{ config('perusahaan.legal.bentuk_badan') }}</dd>
                        </div>
                        @if (config('perusahaan.legal.nib'))
                            <div class="py-3">
                                <dt class="text-ink-muted">{{ __('publik.tentang.nib') }}</dt>
                                <dd class="mt-0.5 font-medium text-ink">{{ config('perusahaan.legal.nib') }}</dd>
                            </div>
                        @endif
                        @if (config('perusahaan.legal.tahun_berdiri'))
                            <div class="py-3">
                                <dt class="text-ink-muted">{{ __('publik.tentang.berdiri') }}</dt>
                                <dd class="mt-0.5 font-medium text-ink">{{ config('perusahaan.legal.tahun_berdiri') }}</dd>
                            </div>
                        @endif
                        <div class="py-3">
                            <dt class="text-ink-muted">{{ __('publik.tentang.kota') }}</dt>
                            <dd class="mt-0.5 font-medium text-ink">{{ config('perusahaan.kontak.kota') }}</dd>
                        </div>
                    </dl>

                    <a href="{{ route('publik.kontak') }}"
                       class="mt-5 block rounded-btn bg-brand-600 px-4 py-2.5 text-center text-sm font-semibold
                              text-white shadow-btn transition duration-200 hover:bg-brand-500 active:scale-[0.98]">
                        {{ __('publik.tentang.hubungi') }}
                    </a>
                </div>
            </aside>

        </div>
    </section>

@endsection
