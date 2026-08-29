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
        {{--
            The buyer door is the loud one — nearly everyone standing here is
            a customer. Staff know where their panel is; their door is quiet.
        --}}
        <div class="mt-12 grid gap-6 md:grid-cols-2">

            <a href="{{ \Filament\Facades\Filament::getPanel('portal')->getLoginUrl() }}"
               class="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-brand-900 via-brand-800
                      to-brand-700 p-8 shadow-md transition hover:shadow-lg focus:outline-none focus:ring-2
                      focus:ring-brand-600 focus:ring-offset-2">
                <span class="inline-flex rounded-lg bg-white/15 px-3 py-1 text-xs font-semibold text-white
                             ring-1 ring-white/25">
                    Pelanggan
                </span>

                <h2 class="mt-5 text-xl font-bold text-white">Portal Pelanggan</h2>

                <p class="mt-3 leading-relaxed text-brand-100">
                    Untuk bengkel, toko sparepart, dan distributor yang sudah terdaftar.
                    Lihat sisa limit kredit, tagihan, dan riwayat pesanan Anda.
                </p>

                <span class="mt-6 inline-flex items-center gap-1.5 font-semibold text-white transition group-hover:gap-2.5">
                    Masuk sebagai pelanggan <span aria-hidden="true">&rarr;</span>
                </span>
            </a>

            <a href="{{ \Filament\Facades\Filament::getPanel('admin')->getLoginUrl() }}"
               class="group rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-900/10 transition
                      hover:shadow-md focus:outline-none focus:ring-2 focus:ring-brand-600 focus:ring-offset-2">
                <span class="inline-flex rounded-lg bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                    Staf
                </span>

                <h2 class="mt-5 text-xl font-bold text-slate-900">Panel Admin</h2>

                <p class="mt-3 leading-relaxed text-slate-600">
                    Untuk tim sales, gudang, keuangan, dan pemilik.
                    Kelola order, stok, penagihan, dan daftar harga.
                </p>

                <span class="mt-6 inline-flex items-center gap-1.5 font-semibold text-slate-700 transition
                             group-hover:gap-2.5 group-hover:text-slate-900">
                    Masuk sebagai staf <span aria-hidden="true">&rarr;</span>
                </span>
            </a>

        </div>

        <div class="mt-12 rounded-2xl bg-slate-50 p-6 text-center ring-1 ring-slate-900/5">
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
