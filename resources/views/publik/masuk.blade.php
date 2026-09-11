@extends('layouts.publik')

@section('judul', __('publik.masuk.judul'))
@section('deskripsi', __('publik.masuk.deskripsi', ['nama' => config('perusahaan.nama')]))

@section('konten')

    <section class="mx-auto max-w-4xl px-4 py-20 sm:py-28">

        <div class="text-center">
            <h1 class="text-4xl font-semibold tracking-[-0.03em] text-ink">{{ __('publik.masuk.judul') }}</h1>
            <p class="mt-3 text-ink-muted">{{ __('publik.masuk.pilih') }}</p>
        </div>

        {{--
            Two separate doors rather than one form that guesses.

            Staff and buyers authenticate on different guards against different
            tables, so a single combined form would have to try both and leak
            which table an address exists in. Two links, no guessing.
        --}}
        {{--
            The buyer door is the loud one — nearly everyone standing here is
            a customer. Staff know where their panel is; their door is quiet.
        --}}
        <div class="mt-12 grid gap-4 md:grid-cols-2">

            <a href="{{ \Filament\Facades\Filament::getPanel('portal')->getLoginUrl() }}"
               class="group relative overflow-hidden rounded-panel bg-linear-160 from-[#0b3a9c] via-brand-600 to-brand-800
                      p-8 text-white shadow-panel transition duration-300 hover:-translate-y-0.5
                      focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_50%_80%_at_100%_0%,rgb(255_255_255/0.12),transparent_70%)]" aria-hidden="true"></div>
                <span class="relative inline-flex rounded-full bg-white/15 px-2.5 py-1 text-xs font-semibold">{{ __('publik.masuk.pelanggan') }}</span>

                <h2 class="relative mt-5 text-xl font-semibold tracking-tight">{{ __('publik.masuk.portal') }}</h2>

                <p class="relative mt-2 leading-relaxed text-white/80">
                    {{ __('publik.masuk.portal_lede') }}
                </p>

                <span class="relative mt-6 inline-flex items-center gap-1.5 font-semibold transition group-hover:gap-2.5">
                    {{ __('publik.masuk.masuk_pelanggan') }} <span aria-hidden="true">&rarr;</span>
                </span>
            </a>

            <a href="{{ \Filament\Facades\Filament::getPanel('admin')->getLoginUrl() }}"
               class="group rounded-panel border border-line bg-white p-8 shadow-card transition duration-300 hover:-translate-y-0.5
                      focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                <span class="inline-flex rounded-full bg-ground-2 px-2.5 py-1 text-xs font-semibold text-ink-muted">{{ __('publik.masuk.staf') }}</span>

                <h2 class="mt-5 text-xl font-semibold tracking-tight text-ink">{{ __('publik.masuk.admin') }}</h2>

                <p class="mt-2 leading-relaxed text-ink-muted">
                    {{ __('publik.masuk.admin_lede') }}
                </p>

                <span class="mt-6 inline-flex items-center gap-1.5 font-semibold text-ink transition group-hover:gap-2.5">
                    {{ __('publik.masuk.masuk_staf') }} <span aria-hidden="true">&rarr;</span>
                </span>
            </a>

        </div>

        <p class="mt-12 text-center text-ink-muted">
            {{ __('publik.masuk.belum_akun') }}
            <a href="{{ route('publik.kontak') }}" class="font-semibold text-brand-600 transition hover:text-brand-700">{{ __('publik.masuk.hubungi') }}</a>
            {{ __('publik.masuk.untuk_daftar') }}
        </p>

    </section>

@endsection
