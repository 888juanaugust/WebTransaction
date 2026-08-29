@extends('layouts.publik')

@section('judul', 'Home')
@section('deskripsi', \App\Support\Perusahaan::text('ringkasan'))

@php
    use App\Support\Perusahaan;

    /*
     * One stroke icon per category, matched on a keyword so a renamed or
     * added category degrades to the neutral box icon instead of breaking.
     * Paths are Heroicons outlines, inlined: the page must not fetch assets.
     */
    $ikonKategori = function (string $nama): string {
        $peta = [
            'HYDRAULIC' => 'M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z',
            'SUSPENSION' => 'M3 7.5 7.5 3m0 0L12 7.5M7.5 3v13.5m13.5 0L16.5 21m0 0L12 16.5m4.5 4.5V7.5',
            'ELECTRIC' => 'm3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z',
            'BEARING' => 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
        ];

        foreach ($peta as $kunci => $path) {
            if (str_contains(strtoupper($nama), $kunci)) {
                return $path;
            }
        }

        return 'm21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9';
    };

    $kategori = Perusahaan::records('kategori');
    $mitra = Perusahaan::records('mitra');
@endphp

@section('konten')

    {{--
        Hero: the company, and nothing but the company. A centred statement,
        one primary action, and below it a panel that puts the mark next to
        the facts a buyer sizes a supplier up by. No catalogue, no customer
        data — wholesale pricing is per customer and lives behind the login.

        The ground carries a faint, oversized copy of the mark instead of a
        decorative pattern: the only ornament on the page is the brand itself.
    --}}
    <section class="relative overflow-hidden">
        <div class="pointer-events-none absolute inset-0" aria-hidden="true">
            <div class="absolute inset-x-0 bottom-0 h-[70%] bg-[radial-gradient(ellipse_55%_60%_at_50%_100%,rgb(7_49_133/0.14),transparent_70%)]"></div>
            <svg class="absolute -right-24 top-8 h-[520px] w-auto text-brand-600 opacity-[0.05] sm:-right-10 sm:h-[640px]"
                 viewBox="0 0 664 686" fill="currentColor">
                <path d="M0,686 L394,686 L394,160 C376,231 372,239 355,284 C291,446 167,594 0,686Z M529,78 L416,144 L416,686 L529,686Z M664,0 L552,64 L552,686 L664,686Z"/>
            </svg>
        </div>

        <div class="relative mx-auto max-w-6xl px-4 pt-16 pb-20 sm:pt-24 sm:pb-24">
            <div class="mx-auto max-w-3xl text-center">
                <h1 class="text-[2.35rem] font-semibold leading-[1.05] tracking-[-0.035em] text-ink text-balance sm:text-6xl">
                    Wholesale automotive parts, priced per customer.
                </h1>

                <p class="mx-auto mt-6 max-w-xl text-lg leading-relaxed text-ink-muted text-pretty">
                    {{ Perusahaan::text('ringkasan') }}
                </p>

                <div class="mt-9 flex flex-wrap justify-center gap-2.5">
                    <a href="{{ route('masuk') }}"
                       class="rounded-btn bg-brand-600 px-5 py-3 text-[15px] font-semibold text-white shadow-btn
                              transition duration-200 hover:bg-brand-500 active:scale-[0.98]
                              focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                        Sign in to your account
                    </a>
                    <a href="{{ route('publik.kontak') }}"
                       class="rounded-btn border border-line-strong bg-white px-5 py-3 text-[15px] font-semibold text-ink
                              transition duration-200 hover:border-ink/30 hover:bg-ground active:scale-[0.98]
                              focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                        Contact us
                    </a>
                </div>
            </div>

            {{--
                The company at a glance. The mark, the name, and the four facts
                from config. Sets expectations immediately: a supplier, not a
                shop.
            --}}
            <div class="reveal mx-auto mt-16 max-w-5xl rounded-panel border border-line bg-white shadow-panel sm:mt-20">
                <div class="grid gap-8 p-7 sm:p-9 lg:grid-cols-[1.1fr_1.5fr] lg:items-center lg:gap-12">
                    <div class="flex items-start gap-5">
                        @if (\App\Support\Branding::hasLogo())
                            <img src="{{ \App\Support\Branding::logoUrl() }}" alt="" aria-hidden="true" class="h-16 w-auto shrink-0 sm:h-20">
                        @endif
                        <div>
                            <p class="text-xl font-semibold tracking-tight text-ink">{{ config('perusahaan.nama') }}</p>
                            <p class="mt-1 text-[15px] text-ink-muted">{{ Perusahaan::text('tagline') }}</p>
                            <p class="mt-4 text-sm leading-relaxed text-ink-muted">
                                We supply <strong class="font-semibold text-ink">workshops, parts shops and
                                distributors</strong>, not retail buyers. Wholesale prices are for registered customers only.
                            </p>
                        </div>
                    </div>

                    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-card border border-line bg-line sm:grid-cols-4">
                        @foreach ([
                            [count($kategori), 'Product categories'],
                            [count(config('perusahaan.merk')), 'Brands supplied'],
                            [config('perusahaan.kontak.kota'), 'Main warehouse'],
                            ['30 days', 'Standard credit term'],
                        ] as [$angka, $label])
                            {{-- dt before dd for the markup; the number reads first on screen. --}}
                            <div class="flex flex-col-reverse justify-end bg-white px-4 py-4">
                                <dt class="mt-1 text-xs text-ink-muted">{{ $label }}</dt>
                                <dd class="text-2xl font-semibold tracking-tight text-ink tabular-nums">{{ $angka }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>
        </div>
    </section>

    {{-- Categories, straight after the hero: what we actually sell. Four items, four cells. --}}
    <section class="reveal mx-auto max-w-6xl px-4 py-20 sm:py-28">
        @include('publik.partials.kicker', [
            'judul' => 'Product categories',
            'lede' => 'The four categories workshops need most often, kept in stock.',
        ])

        <div class="mt-10 grid gap-3.5 md:grid-cols-12">
            @foreach ($kategori as $i => $item)
                @php
                    // Wide, narrow, narrow, wide: a rhythm rather than a row.
                    $lebar = in_array($i % 4, [0, 3], true) ? 'md:col-span-7' : 'md:col-span-5';
                    $tinted = $i % 4 === 0;
                @endphp
                <div @class([
                    'group relative flex flex-col justify-between overflow-hidden rounded-card p-6 transition duration-300 md:min-h-[200px]',
                    $lebar,
                    'bg-linear-135 from-brand-600 to-[#1b4bb0] text-white' => $tinted,
                    'border border-line bg-white hover:-translate-y-0.5 hover:shadow-card' => ! $tinted,
                ])>
                    @if ($tinted)
                        <div class="pointer-events-none absolute -top-16 -right-10 h-64 w-64 rounded-full border-[36px] border-white/[0.08]" aria-hidden="true"></div>
                    @endif

                    <span @class([
                        'relative inline-flex h-10 w-10 items-center justify-center rounded-[10px]',
                        'bg-white/15 text-white' => $tinted,
                        'bg-brand-50 text-brand-600' => ! $tinted,
                    ])>
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $ikonKategori($item['nama']) }}"/>
                        </svg>
                    </span>

                    <div class="relative mt-6 md:mt-8">
                        <h3 class="text-xl font-semibold tracking-tight">{{ $item['nama'] }}</h3>
                        <p @class(['mt-1.5 max-w-md text-[15px] leading-relaxed', 'text-white/80' => $tinted, 'text-ink-muted' => ! $tinted])>
                            {{ $item['deskripsi'] }}
                        </p>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- No SKU list and no prices: wholesale pricing is per customer. --}}
        <p class="mt-6 text-sm text-ink-muted">
            The full product list, with prices, is available to registered customers after signing in.
        </p>
    </section>

    {{-- Brands: the names are the content. Wordmark tiles until the real brand logos are supplied. --}}
    <section class="reveal mx-auto max-w-6xl px-4 py-4 text-center sm:py-8">
        <p class="text-[15px] font-medium text-ink-muted">Brands we carry, available for delivery across Indonesia</p>

        <ul class="mx-auto mt-8 grid max-w-5xl grid-cols-2 gap-2.5 sm:grid-cols-4 lg:grid-cols-7">
            @foreach (config('perusahaan.merk') as $merk)
                <li class="flex items-center justify-center rounded-card border border-line bg-white px-3 py-5 text-[13px]
                           font-semibold uppercase tracking-[0.12em] text-brand-700 transition duration-200 hover:border-brand-200">
                    {{ $merk }}
                </li>
            @endforeach
        </ul>
    </section>

    {{-- How an account opens: the three steps between "interested" and "ordering". --}}
    <section class="reveal mx-auto max-w-6xl px-4 py-20 sm:py-28">
        <div class="grid gap-10 rounded-panel border border-line bg-white p-7 shadow-[0_1px_2px_rgb(22_24_29/0.03)] sm:p-12 lg:grid-cols-[4fr_8fr] lg:gap-16">
            <div>
                @include('publik.partials.kicker', [
                    'judul' => 'Become a customer in three steps',
                    'lede' => 'A wholesale account opens once your business details are verified. There is no self-service sign-up.',
                ])
                <a href="{{ route('publik.kontak') }}"
                   class="mt-6 inline-flex items-center gap-1.5 text-[15px] font-semibold text-brand-600 transition hover:text-brand-700 hover:gap-2.5">
                    Contact us <span aria-hidden="true">&rarr;</span>
                </a>
            </div>

            <ol class="divide-y divide-line">
                @foreach ([
                    ['Send your business details', 'Business name, address and tax number (NPWP) by WhatsApp or email. The account opens after the details are verified.'],
                    ['Agree prices and a credit limit', 'Our team sets your price tier and credit limit to the scale of your business, with clear payment terms.'],
                    ['Order through the portal', 'A catalogue at your prices, routine orders repeated in one click, and your invoices whenever you need them.'],
                ] as $i => [$judul, $isi])
                    <li class="grid grid-cols-[44px_1fr] gap-5 py-7 first:pt-0 last:pb-0">
                        <span @class([
                            'flex h-10 w-10 items-center justify-center rounded-[12px] text-[15px] font-semibold',
                            'bg-brand-600 text-white' => $i === 0,
                            'bg-ground-2 text-brand-600' => $i !== 0,
                        ])>{{ $i + 1 }}</span>
                        <div>
                            <h3 class="text-lg font-semibold tracking-tight text-ink">{{ $judul }}</h3>
                            <p class="mt-1.5 text-[15px] leading-relaxed text-ink-muted">{{ $isi }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- About, with the partners condensed beside it: the full list has its own page. --}}
    <section class="reveal mx-auto max-w-6xl px-4 py-12 sm:py-16">
        <div class="grid gap-12 lg:grid-cols-[5fr_7fr] lg:gap-24">
            <div>
                @include('publik.partials.kicker', ['judul' => 'About the company'])

                <div class="mt-5 space-y-4">
                    @foreach (array_slice(Perusahaan::list('profil'), 0, 2) as $paragraf)
                        <p class="leading-relaxed text-ink-muted">{{ $paragraf }}</p>
                    @endforeach
                </div>

                <a href="{{ route('publik.tentang') }}"
                   class="mt-6 inline-flex items-center gap-1.5 text-[15px] font-semibold text-brand-600 transition hover:text-brand-700 hover:gap-2.5">
                    More about us <span aria-hidden="true">&rarr;</span>
                </a>
            </div>

            <div>
                <div class="flex items-baseline justify-between gap-4 pb-3">
                    <h3 class="text-[15px] font-semibold text-ink">Partners</h3>
                    <a href="{{ route('publik.mitra') }}" class="text-sm font-medium text-brand-600 transition hover:text-brand-700">
                        See all partners <span aria-hidden="true">&rarr;</span>
                    </a>
                </div>

                <ul class="divide-y divide-line border-y border-line">
                    @foreach ($mitra as $item)
                        <li class="grid gap-1 py-5 sm:grid-cols-[1fr_160px_90px] sm:items-center sm:gap-6">
                            <div>
                                <p class="font-semibold text-ink">{{ $item['nama'] }}</p>
                                <p class="mt-0.5 text-sm text-ink-muted">{{ $item['deskripsi'] }}</p>
                            </div>
                            <p class="text-sm text-ink-muted">{{ $item['bidang'] }}</p>
                            @if (! empty($item['sejak']))
                                <p class="text-sm text-ink-muted sm:text-right">since {{ $item['sejak'] }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    {{-- Closing action: the one blue surface on the page. --}}
    <section class="reveal mx-auto max-w-6xl px-4 pt-20 sm:pt-28">
        <div class="relative overflow-hidden rounded-[22px] bg-linear-160 from-[#0b3a9c] via-brand-600 to-brand-800 px-8 py-12 text-white shadow-panel sm:px-14 sm:py-14">
            <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_40%_80%_at_90%_50%,rgb(255_255_255/0.10),transparent_70%)]" aria-hidden="true"></div>
            <div class="relative flex flex-col items-start justify-between gap-8 sm:flex-row sm:items-center">
                <div>
                    <h2 class="text-3xl font-semibold tracking-[-0.03em] sm:text-4xl">Already a customer?</h2>
                    <p class="mt-2 max-w-lg text-[17px] leading-relaxed text-white/80">
                        Sign in to see your prices, your remaining credit limit, invoices and order history.
                    </p>
                </div>
                <a href="{{ route('masuk') }}"
                   class="shrink-0 rounded-btn bg-white px-5 py-3 text-[15px] font-semibold text-brand-600 whitespace-nowrap
                          transition duration-200 hover:bg-brand-50 active:scale-[0.98]
                          focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                    Sign in to your account
                </a>
            </div>
        </div>
    </section>

@endsection
