@extends('layouts.publik')

@section('judul', __('publik.kontak.judul'))
@section('deskripsi', __('publik.kontak.deskripsi', ['nama' => config('perusahaan.nama')]))

@section('konten')

    @include('publik.partials.hero-halaman', [
        'judul' => __('publik.kontak.judul'),
        'lede' => __('publik.kontak.lede'),
    ])

    <section class="mx-auto max-w-6xl px-4 py-16 sm:py-20">
        <div class="grid gap-12 lg:grid-cols-2 lg:gap-16">

            <div>
                <h2 class="text-2xl font-semibold tracking-tight text-ink">{{ __('publik.kontak.rincian') }}</h2>

                <dl class="mt-6 divide-y divide-line border-y border-line">
                    <div class="grid gap-1 py-4 sm:grid-cols-[150px_1fr] sm:gap-6">
                        <dt class="text-sm font-medium text-ink-muted">{{ __('publik.kontak.alamat') }}</dt>
                        <dd class="leading-relaxed text-ink">{{ config('perusahaan.kontak.alamat') }}</dd>
                    </div>

                    <div class="grid gap-1 py-4 sm:grid-cols-[150px_1fr] sm:gap-6">
                        <dt class="text-sm font-medium text-ink-muted">{{ __('publik.kontak.telepon') }}</dt>
                        <dd>
                            <a class="font-medium text-brand-600 transition hover:text-brand-700"
                               href="tel:{{ preg_replace('/[^0-9+]/', '', config('perusahaan.kontak.telepon')) }}">
                                {{ config('perusahaan.kontak.telepon') }}
                            </a>
                        </dd>
                    </div>

                    <div class="grid gap-1 py-4 sm:grid-cols-[150px_1fr] sm:gap-6">
                        <dt class="text-sm font-medium text-ink-muted">{{ __('publik.kontak.whatsapp') }}</dt>
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
                        <dt class="text-sm font-medium text-ink-muted">{{ __('publik.kontak.email') }}</dt>
                        <dd>
                            <a class="font-medium text-brand-600 transition hover:text-brand-700"
                               href="mailto:{{ config('perusahaan.kontak.email') }}">
                                {{ config('perusahaan.kontak.email') }}
                            </a>
                        </dd>
                    </div>

                    <div class="grid gap-1 py-4 sm:grid-cols-[150px_1fr] sm:gap-6">
                        <dt class="text-sm font-medium text-ink-muted">{{ __('publik.kontak.jam') }}</dt>
                        <dd class="text-ink">{{ \App\Support\Perusahaan::jamOperasional() }}</dd>
                    </div>
                </dl>
            </div>

            <div class="space-y-4">
                <div class="rounded-panel border border-line bg-white p-7 shadow-card">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ __('publik.kontak.buka_akun') }}</h2>
                    <p class="mt-2 leading-relaxed text-ink-muted">
                        {{ __('publik.kontak.buka_akun_lede') }}
                    </p>
                    <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', config('perusahaan.kontak.whatsapp')) }}"
                       target="_blank" rel="noopener"
                       class="mt-6 inline-block rounded-btn bg-brand-600 px-5 py-3 text-[15px] font-semibold text-white
                              shadow-btn transition duration-200 hover:bg-brand-500 active:scale-[0.98]">
                        {{ __('publik.kontak.kirim_wa') }}
                    </a>
                </div>

                <div class="rounded-panel border border-line bg-white p-7">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ __('publik.kontak.sudah_akun') }}</h2>
                    <p class="mt-2 leading-relaxed text-ink-muted">
                        {{ __('publik.kontak.sudah_akun_lede') }}
                    </p>
                    <a href="{{ route('masuk') }}"
                       class="mt-6 inline-block rounded-btn border border-line-strong bg-white px-5 py-3 text-[15px]
                              font-semibold text-ink transition duration-200 hover:border-ink/30 hover:bg-ground active:scale-[0.98]">
                        {{ __('publik.kontak.masuk_akun') }}
                    </a>
                </div>
            </div>

        </div>
    </section>

    {{--
        Our branches, and which one is nearest.

        The list is public information — a name, an address, a phone number.
        The nearest-branch answer is worked out in the visitor's browser
        against that list: their position never leaves the page, which is
        what lets the privacy notice say so. The browser only asks for
        location when the button is pressed, never on load.
    --}}
    @php
        $cabang = \App\Models\Region::query()
            ->where('aktif', true)
            ->orderBy('kode')
            ->get(['kode', 'nama', 'alamat', 'telepon', 'lintang', 'bujur']);
    @endphp

    @if ($cabang->isNotEmpty())
        <section id="cabang" class="mx-auto max-w-6xl scroll-mt-24 px-4 pb-4 sm:pb-8">
            <div class="rounded-panel border border-line bg-white p-7 shadow-card sm:p-9">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-2xl font-semibold tracking-tight text-ink">{{ __('publik.kontak.cabang') }}</h2>
                        <p class="mt-2 max-w-xl leading-relaxed text-ink-muted">{{ __('publik.kontak.cabang_lede') }}</p>
                    </div>
                    <button type="button" id="cabang-cari"
                            class="shrink-0 rounded-btn bg-brand-600 px-5 py-3 text-[15px] font-semibold text-white shadow-btn
                                   transition duration-200 hover:bg-brand-500 active:scale-[0.98]"
                            data-mencari="{{ __('publik.kontak.cabang_mencari') }}"
                            data-hasil="{{ __('publik.kontak.cabang_hasil') }}"
                            data-ditolak="{{ __('publik.kontak.cabang_ditolak') }}"
                            data-tidak-ada="{{ __('publik.kontak.cabang_tidak_ada') }}"
                            data-tanpa-dukungan="{{ __('publik.kontak.cabang_tanpa_dukungan') }}">
                        {{ __('publik.kontak.cabang_terdekat') }}
                    </button>
                </div>

                <p id="cabang-hasil" class="mt-4 min-h-6 text-[15px] font-medium text-ink" aria-live="polite"></p>

                <ul class="mt-4 divide-y divide-line border-y border-line" id="cabang-daftar">
                    @foreach ($cabang as $c)
                        <li class="grid gap-1 py-4 sm:grid-cols-[180px_1fr_160px] sm:gap-6" data-cabang="{{ $c->kode }}">
                            <p class="font-semibold text-ink">{{ $c->nama }}</p>
                            <p class="text-sm leading-relaxed text-ink-muted">{{ $c->alamat }}</p>
                            <p class="text-sm text-ink-muted sm:text-right">{{ $c->telepon }}</p>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>

        {{-- A data block, not a script: CSP never executes it, and the browser never fetches it. --}}
        <script type="application/json" id="cabang-data"><?php echo json_encode(
            $cabang->filter(fn ($c) => $c->punyaKoordinat())->values()->map(fn ($c) => [
                'kode' => $c->kode, 'nama' => $c->nama, 'lat' => $c->lintang, 'lng' => $c->bujur,
            ])->all(),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        ); ?></script>

        @push('kaki')
            <script nonce="{{ $cspNonce ?? '' }}">
                (() => {
                    const tombol = document.getElementById('cabang-cari');
                    const hasil = document.getElementById('cabang-hasil');
                    if (! tombol || ! hasil) return;

                    const cabang = JSON.parse(document.getElementById('cabang-data').textContent || '[]');

                    // Great-circle distance, kilometres. Good to well under a percent
                    // at these scales, which is more than "about 12 km" needs.
                    const jarak = (a, b) => {
                        const r = Math.PI / 180, R = 6371;
                        const dLat = (b.lat - a.lat) * r, dLng = (b.lng - a.lng) * r;
                        const h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * r) * Math.cos(b.lat * r) * Math.sin(dLng / 2) ** 2;
                        return 2 * R * Math.asin(Math.sqrt(h));
                    };

                    tombol.addEventListener('click', () => {
                        if (cabang.length === 0) { hasil.textContent = tombol.dataset.tidakAda; return; }
                        if (! ('geolocation' in navigator)) { hasil.textContent = tombol.dataset.tanpaDukungan; return; }

                        hasil.textContent = tombol.dataset.mencari;

                        navigator.geolocation.getCurrentPosition((pos) => {
                            const saya = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                            const terdekat = cabang
                                .map((c) => ({ ...c, km: jarak(saya, c) }))
                                .sort((x, y) => x.km - y.km)[0];

                            hasil.textContent = tombol.dataset.hasil
                                .replace(':nama', terdekat.nama)
                                .replace(':jarak', terdekat.km < 10 ? terdekat.km.toFixed(1) : Math.round(terdekat.km));

                            document.querySelectorAll('[data-cabang]').forEach((li) => {
                                li.classList.toggle('bg-brand-50', li.dataset.cabang === terdekat.kode);
                                li.classList.toggle('-mx-3', li.dataset.cabang === terdekat.kode);
                                li.classList.toggle('px-3', li.dataset.cabang === terdekat.kode);
                                li.classList.toggle('rounded-[10px]', li.dataset.cabang === terdekat.kode);
                            });
                        }, () => {
                            hasil.textContent = tombol.dataset.ditolak;
                        }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 });
                    });
                })();
            </script>
        @endpush
    @endif

@endsection
