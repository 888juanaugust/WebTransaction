@extends('layouts.publik')

@section('judul', 'Masuk')
@section('deskripsi', 'Masuk ke portal pelanggan atau panel staf ' . config('perusahaan.nama') . '.')

@section('konten')

    <section class="mx-auto max-w-4xl px-4 py-20">

        <div class="text-center">
            <h1 class="text-3xl font-bold tracking-tight text-slate-900">Masuk</h1>
            <p class="mt-3 text-slate-600">Pilih jenis akun Anda.</p>
        </div>

        {{--
            Two separate doors rather than one form that guesses.

            Staff and buyers authenticate on different guards against different
            tables, so a single combined form would have to try both and leak
            which table an address exists in. Two links, no guessing.
        --}}
        <div class="mt-12 grid gap-6 md:grid-cols-2">

            <a href="{{ \Filament\Facades\Filament::getPanel('portal')->getLoginUrl() }}"
               class="group rounded-2xl border-2 border-brand-600 bg-brand-50/40 p-8 transition
                      hover:bg-brand-50 focus:outline-none focus:ring-2 focus:ring-brand-600 focus:ring-offset-2">
                <span class="inline-flex rounded-lg bg-brand-600 px-3 py-1 text-xs font-semibold text-white">
                    Pelanggan
                </span>

                <h2 class="mt-5 text-xl font-bold text-slate-900">Portal Pelanggan</h2>

                <p class="mt-3 leading-relaxed text-slate-600">
                    Untuk bengkel, toko sparepart, dan distributor yang sudah terdaftar.
                    Lihat sisa limit kredit, tagihan, dan riwayat pesanan Anda.
                </p>

                <span class="mt-6 inline-block font-semibold text-brand-600 group-hover:text-brand-700">
                    Masuk sebagai pelanggan →
                </span>
            </a>

            <a href="{{ \Filament\Facades\Filament::getPanel('admin')->getLoginUrl() }}"
               class="group rounded-2xl border-2 border-slate-200 p-8 transition hover:border-slate-300
                      focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2">
                <span class="inline-flex rounded-lg bg-slate-700 px-3 py-1 text-xs font-semibold text-white">
                    Staf
                </span>

                <h2 class="mt-5 text-xl font-bold text-slate-900">Panel Admin</h2>

                <p class="mt-3 leading-relaxed text-slate-600">
                    Untuk tim sales, gudang, keuangan, dan pemilik.
                    Kelola order, stok, penagihan, dan daftar harga.
                </p>

                <span class="mt-6 inline-block font-semibold text-slate-700 group-hover:text-slate-900">
                    Masuk sebagai staf →
                </span>
            </a>

        </div>

        <div class="mt-12 rounded-xl border border-slate-200 bg-slate-50 p-6 text-center">
            <p class="text-slate-600">
                Belum punya akun pelanggan?
                <a href="{{ route('publik.kontak') }}" class="font-semibold text-brand-600 hover:text-brand-700">
                    Hubungi kami
                </a>
                untuk pendaftaran.
            </p>
        </div>

    </section>

@endsection
