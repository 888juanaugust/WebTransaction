@extends('layouts.publik')

@section('judul', 'Tentang Kami')
@section('deskripsi', 'Profil ' . config('perusahaan.nama') . ' — distributor grosir suku cadang otomotif.')

@section('konten')

    <section class="border-b border-slate-100 bg-brand-50">
        <div class="mx-auto max-w-6xl px-4 py-16">
            <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">Tentang Kami</h1>
            <p class="mt-4 max-w-2xl text-lg text-slate-600">
                {{ \App\Support\Perusahaan::text('tagline') }}
            </p>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-16">
        <div class="grid gap-12 lg:grid-cols-3">

            <div class="lg:col-span-2">
                <h2 class="text-xl font-bold text-slate-900">Profil perusahaan</h2>

                <div class="mt-6 space-y-5">
                    @foreach (\App\Support\Perusahaan::list('profil') as $paragraf)
                        <p class="leading-relaxed text-slate-600">{{ $paragraf }}</p>
                    @endforeach
                </div>

                <h2 class="mt-14 text-xl font-bold text-slate-900">Siapa yang kami layani</h2>
                <div class="mt-6 grid gap-4 sm:grid-cols-3">
                    @foreach ([
                        ['Bengkel', 'Kebutuhan perbaikan harian dengan stok yang bisa diandalkan.'],
                        ['Toko sparepart', 'Pasokan rutin untuk kebutuhan penjualan kembali.'],
                        ['Distributor', 'Volume besar dengan skema harga dan termin khusus.'],
                    ] as [$judul, $isi])
                        <div class="rounded-xl border border-slate-200 p-5">
                            <h3 class="font-semibold text-brand-700">{{ $judul }}</h3>
                            <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $isi }}</p>
                        </div>
                    @endforeach
                </div>

                <h2 class="mt-14 text-xl font-bold text-slate-900">Cara kerja</h2>
                <ol class="mt-6 space-y-4">
                    @foreach ([
                        'Pendaftaran akun pelanggan dan verifikasi data usaha.',
                        'Penetapan harga dan limit kredit sesuai kesepakatan.',
                        'Pemesanan melalui tim sales kami atau portal pelanggan.',
                        'Pengiriman disertai surat jalan dan faktur pajak.',
                    ] as $i => $langkah)
                        <li class="flex gap-4">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full
                                         bg-brand-600 text-sm font-semibold text-white">{{ $i + 1 }}</span>
                            <span class="pt-0.5 leading-relaxed text-slate-600">{{ $langkah }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>

            <aside class="lg:col-span-1">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-6">
                    <h2 class="font-semibold text-slate-900">Identitas perusahaan</h2>

                    <dl class="mt-4 space-y-3 text-sm">
                        <div>
                            <dt class="text-slate-500">Nama</dt>
                            <dd class="font-medium text-slate-800">{{ config('perusahaan.nama') }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Bentuk badan usaha</dt>
                            <dd class="font-medium text-slate-800">{{ config('perusahaan.legal.bentuk_badan') }}</dd>
                        </div>
                        @if (config('perusahaan.legal.nib'))
                            <div>
                                <dt class="text-slate-500">NIB</dt>
                                <dd class="font-medium text-slate-800">{{ config('perusahaan.legal.nib') }}</dd>
                            </div>
                        @endif
                        @if (config('perusahaan.legal.tahun_berdiri'))
                            <div>
                                <dt class="text-slate-500">Berdiri sejak</dt>
                                <dd class="font-medium text-slate-800">{{ config('perusahaan.legal.tahun_berdiri') }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-slate-500">Kota</dt>
                            <dd class="font-medium text-slate-800">{{ config('perusahaan.kontak.kota') }}</dd>
                        </div>
                    </dl>

                    <a href="{{ route('publik.kontak') }}"
                       class="mt-6 block rounded-md bg-brand-600 px-4 py-2.5 text-center text-sm font-semibold
                              text-white transition hover:bg-brand-700">
                        Hubungi kami
                    </a>
                </div>
            </aside>

        </div>
    </section>

@endsection
