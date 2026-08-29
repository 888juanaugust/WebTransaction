@extends('layouts.publik')

@section('judul', 'Contact')
@section('deskripsi', 'Contact ' . config('perusahaan.nama') . ' for orders, a customer account, or a partnership.')

@section('konten')

    @include('publik.partials.hero-halaman', [
        'judul' => 'Contact',
        'lede' => 'For orders, opening a customer account, or a partnership.',
    ])

    <section class="mx-auto max-w-6xl px-4 py-16 sm:py-20">
        <div class="grid gap-12 lg:grid-cols-2 lg:gap-16">

            <div>
                <h2 class="text-2xl font-semibold tracking-tight text-ink">Contact details</h2>

                <dl class="mt-6 divide-y divide-line border-y border-line">
                    <div class="grid gap-1 py-4 sm:grid-cols-[150px_1fr] sm:gap-6">
                        <dt class="text-sm font-medium text-ink-muted">Address</dt>
                        <dd class="leading-relaxed text-ink">{{ config('perusahaan.kontak.alamat') }}</dd>
                    </div>

                    <div class="grid gap-1 py-4 sm:grid-cols-[150px_1fr] sm:gap-6">
                        <dt class="text-sm font-medium text-ink-muted">Phone</dt>
                        <dd>
                            <a class="font-medium text-brand-600 transition hover:text-brand-700"
                               href="tel:{{ preg_replace('/[^0-9+]/', '', config('perusahaan.kontak.telepon')) }}">
                                {{ config('perusahaan.kontak.telepon') }}
                            </a>
                        </dd>
                    </div>

                    <div class="grid gap-1 py-4 sm:grid-cols-[150px_1fr] sm:gap-6">
                        <dt class="text-sm font-medium text-ink-muted">WhatsApp</dt>
                        <dd>
                            {{-- wa.me needs the number bare: no +, no spaces. --}}
                            <a class="font-medium text-brand-600 transition hover:text-brand-700"
                               target="_blank" rel="noopener"
                               href="https://wa.me/{{ preg_replace('/[^0-9]/', '', config('perusahaan.kontak.whatsapp')) }}">
                                {{ config('perusahaan.kontak.whatsapp') }}
                            </a>
                        </dd>
                    </div>

                    <div class="grid gap-1 py-4 sm:grid-cols-[150px_1fr] sm:gap-6">
                        <dt class="text-sm font-medium text-ink-muted">Email</dt>
                        <dd>
                            <a class="font-medium text-brand-600 transition hover:text-brand-700"
                               href="mailto:{{ config('perusahaan.kontak.email') }}">
                                {{ config('perusahaan.kontak.email') }}
                            </a>
                        </dd>
                    </div>

                    <div class="grid gap-1 py-4 sm:grid-cols-[150px_1fr] sm:gap-6">
                        <dt class="text-sm font-medium text-ink-muted">Business hours</dt>
                        <dd class="text-ink">{{ \App\Support\Perusahaan::text('kontak.business_hours') }}</dd>
                    </div>
                </dl>
            </div>

            <div class="space-y-4">
                <div class="rounded-panel border border-line bg-white p-7 shadow-card">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">Want to open a customer account?</h2>
                    <p class="mt-2 leading-relaxed text-ink-muted">
                        Wholesale accounts open after your business details are verified. Message us on
                        WhatsApp or by email with your business name, address and tax number (NPWP).
                    </p>
                    <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', config('perusahaan.kontak.whatsapp')) }}"
                       target="_blank" rel="noopener"
                       class="mt-6 inline-block rounded-btn bg-brand-600 px-5 py-3 text-[15px] font-semibold text-white
                              shadow-btn transition duration-200 hover:bg-brand-500 active:scale-[0.98]">
                        Message us on WhatsApp
                    </a>
                </div>

                <div class="rounded-panel border border-line bg-white p-7">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">Already have an account?</h2>
                    <p class="mt-2 leading-relaxed text-ink-muted">
                        Sign in to see your prices, your remaining credit limit and your invoices.
                    </p>
                    <a href="{{ route('masuk') }}"
                       class="mt-6 inline-block rounded-btn border border-line-strong bg-white px-5 py-3 text-[15px]
                              font-semibold text-ink transition duration-200 hover:border-ink/30 hover:bg-ground active:scale-[0.98]">
                        Sign in to your account
                    </a>
                </div>
            </div>

        </div>
    </section>

@endsection
