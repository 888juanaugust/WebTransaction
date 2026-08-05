@extends('layouts.publik')

@section('judul', 'Kontak')
@section('deskripsi', 'Hubungi ' . config('perusahaan.nama') . ' untuk pemesanan dan kerja sama.')

@section('konten')

    <section class="border-b border-powder-200 bg-powder-100">
        <div class="mx-auto max-w-6xl px-4 py-16">
            <h1 class="text-3xl font-bold tracking-tight text-brand-900 sm:text-4xl">Kontak</h1>
            <p class="mt-4 max-w-2xl text-lg text-brand-900/70">
                Hubungi kami untuk pemesanan, pembukaan akun pelanggan, atau kerja sama.
            </p>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-16">
        <div class="grid gap-10 lg:grid-cols-2">

            <div>
                <h2 class="text-xl font-bold text-brand-900">Informasi kontak</h2>

                <dl class="mt-8 space-y-7">
                    <div>
                        <dt class="text-sm font-semibold uppercase tracking-wide text-brand-900/60">Alamat</dt>
                        <dd class="mt-1 leading-relaxed text-brand-900">{{ config('perusahaan.kontak.alamat') }}</dd>
                    </div>

                    <div>
                        <dt class="text-sm font-semibold uppercase tracking-wide text-brand-900/60">Telepon</dt>
                        <dd class="mt-1">
                            <a class="text-brand-600 hover:text-brand-700"
                               href="tel:{{ preg_replace('/[^0-9+]/', '', config('perusahaan.kontak.telepon')) }}">
                                {{ config('perusahaan.kontak.telepon') }}
                            </a>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm font-semibold uppercase tracking-wide text-brand-900/60">WhatsApp</dt>
                        <dd class="mt-1">
                            {{-- wa.me needs the number bare: no +, no spaces. --}}
                            <a class="text-brand-600 hover:text-brand-700"
                               target="_blank" rel="noopener"
                               href="https://wa.me/{{ preg_replace('/[^0-9]/', '', config('perusahaan.kontak.whatsapp')) }}">
                                {{ config('perusahaan.kontak.whatsapp') }}
                            </a>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm font-semibold uppercase tracking-wide text-brand-900/60">Email</dt>
                        <dd class="mt-1">
                            <a class="text-brand-600 hover:text-brand-700"
                               href="mailto:{{ config('perusahaan.kontak.email') }}">
                                {{ config('perusahaan.kontak.email') }}
                            </a>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm font-semibold uppercase tracking-wide text-brand-900/60">Jam operasional</dt>
                        <dd class="mt-1 text-brand-900">
                            {{ \App\Support\Perusahaan::text('kontak.jam_operasional') }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="space-y-6">
                <div class="rounded-xl border border-powder-200 bg-powder-100 p-7">
                    <h2 class="text-lg font-semibold text-brand-900">Ingin membuka akun pelanggan?</h2>
                    <p class="mt-3 leading-relaxed text-brand-900/70">
                        Akun pelanggan grosir dibuka setelah verifikasi data usaha. Hubungi kami melalui
                        WhatsApp atau email dengan menyertakan nama usaha, alamat, dan NPWP.
                    </p>
                    <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', config('perusahaan.kontak.whatsapp')) }}"
                       target="_blank" rel="noopener"
                       class="mt-6 inline-block rounded-md bg-brand-600 px-6 py-3 text-sm font-semibold text-white
                              transition hover:bg-brand-700">
                        Hubungi via WhatsApp
                    </a>
                </div>

                <div class="rounded-xl border border-powder-200 p-7">
                    <h2 class="text-lg font-semibold text-brand-900">Sudah punya akun?</h2>
                    <p class="mt-3 leading-relaxed text-brand-900/70">
                        Masuk untuk melihat harga Anda, sisa limit kredit, dan faktur.
                    </p>
                    <a href="{{ route('masuk') }}"
                       class="mt-6 inline-block rounded-md border border-powder-300 bg-white px-6 py-3 text-sm
                              font-semibold text-brand-800 transition hover:border-brand-600 hover:text-brand-700">
                        Masuk ke akun
                    </a>
                </div>
            </div>

        </div>
    </section>

@endsection
