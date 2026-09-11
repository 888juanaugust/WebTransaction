{{--
    The promo carousel — a running slide at the top of the landing page.

    Only renders when the Owner has at least one active promotion with an
    image; with none, the page has no carousel and no placeholder. Slides are
    typed in Pengaturan perusahaan and overlaid from the database at boot.

    Built without a library, because the public site loads no third-party
    script and runs under an enforced CSP. The track is a scroll-snap row,
    so it works as a swipeable strip with no JavaScript at all; the script
    (nonced, in the layout's footer stack) adds the arrows, the dots and the
    auto-advance. Auto-advance pauses while the pointer or focus is on it and
    never runs for a visitor who asked for reduced motion.

    Images come from our own server via the `public` disk — the privacy
    notice's "every image from our own server" holds. Alt text is the slide's
    title, so a screen reader hears the promotion and not "image".
--}}
@php
    $promo = collect(\App\Support\Perusahaan::records('promo'))
        ->filter(fn (array $p) => ! empty($p['aktif']) && ! empty($p['gambar']))
        ->values();
@endphp

@if ($promo->isNotEmpty())
    <section class="mx-auto max-w-6xl px-4 pt-4 sm:pt-6"
             id="promo" aria-roledescription="carousel" aria-label="{{ __('publik.promo.label') }}">
        <div class="promo relative overflow-hidden rounded-panel border border-line bg-white shadow-card">
            <ul class="promo-track" id="promo-track" tabindex="0">
                @foreach ($promo as $i => $slide)
                    <li class="promo-slide relative" role="group" aria-roledescription="slide"
                        aria-label="{{ $i + 1 }} / {{ $promo->count() }}" data-slide="{{ $i }}">
                        {{--
                            asset(), not Storage::url(): the public disk builds
                            its URL from APP_URL, and an image whose host is
                            not the page's own host is not 'self' — the CSP
                            refused every slide the first time this ran on a
                            different origin. asset() follows the request.
                        --}}
                        <img src="{{ asset('storage/'.ltrim((string) $slide['gambar'], '/')) }}"
                             alt="{{ $slide['judul'] ?? '' }}"
                             class="block aspect-[21/9] w-full object-cover sm:aspect-[3/1]"
                             @if ($i > 0) loading="lazy" @endif
                             decoding="async">

                        @if (! empty($slide['judul']) || ! empty($slide['teks']))
                            <div class="pointer-events-none absolute inset-x-0 bottom-0 bg-linear-to-t from-black/70 via-black/30 to-transparent px-5 pb-5 pt-16 text-white sm:px-8 sm:pb-7">
                                @if (! empty($slide['judul']))
                                    <p class="text-lg font-semibold tracking-tight sm:text-2xl">{{ $slide['judul'] }}</p>
                                @endif
                                @if (! empty($slide['teks']))
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-white/85 sm:text-[15px]">{{ $slide['teks'] }}</p>
                                @endif
                                @if (! empty($slide['tautan']))
                                    <a href="{{ $slide['tautan'] }}"
                                       class="pointer-events-auto mt-3 inline-flex items-center gap-1.5 rounded-btn bg-white px-4 py-2 text-sm font-semibold text-brand-600
                                              transition hover:bg-brand-50">
                                        {{ __('publik.promo.selengkapnya') }} <span aria-hidden="true">&rarr;</span>
                                    </a>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($promo->count() > 1)
                <button type="button" class="promo-arrow left-3" data-promo="prev" aria-label="{{ __('publik.promo.sebelumnya') }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/>
                    </svg>
                </button>
                <button type="button" class="promo-arrow right-3" data-promo="next" aria-label="{{ __('publik.promo.berikutnya') }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/>
                    </svg>
                </button>

                <div class="promo-dots" role="tablist">
                    @foreach ($promo as $i => $slide)
                        <button type="button" class="promo-dot" role="tab" data-promo-dot="{{ $i }}"
                                aria-label="{{ __('publik.promo.ke', ['nomor' => $i + 1]) }}"
                                @if ($i === 0) aria-selected="true" @endif></button>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    @push('kaki')
        <script nonce="{{ $cspNonce ?? '' }}">
            (() => {
                const track = document.getElementById('promo-track');
                if (! track) return;

                const slides = Array.from(track.querySelectorAll('.promo-slide'));
                const dots = Array.from(document.querySelectorAll('[data-promo-dot]'));
                const jumlah = slides.length;
                let aktif = 0;
                let jeda = false;

                const ke = (i) => {
                    aktif = (i + jumlah) % jumlah;
                    track.scrollTo({ left: slides[aktif].offsetLeft, behavior: 'smooth' });
                };

                document.querySelector('[data-promo="prev"]')?.addEventListener('click', () => ke(aktif - 1));
                document.querySelector('[data-promo="next"]')?.addEventListener('click', () => ke(aktif + 1));
                dots.forEach((d) => d.addEventListener('click', () => ke(Number(d.dataset.promoDot))));

                // Which slide is showing, read from the scroll position — so a
                // finger swipe and the buttons agree about where we are.
                if ('IntersectionObserver' in window) {
                    new IntersectionObserver((entries) => {
                        for (const e of entries) {
                            if (e.isIntersecting && e.intersectionRatio > 0.6) {
                                aktif = Number(e.target.dataset.slide);
                                dots.forEach((d, i) => d.setAttribute('aria-selected', String(i === aktif)));
                            }
                        }
                    }, { root: track, threshold: 0.6 }).observe && slides.forEach((s) => {
                        new IntersectionObserver((entries) => {
                            for (const e of entries) {
                                if (e.isIntersecting && e.intersectionRatio > 0.6) {
                                    aktif = Number(e.target.dataset.slide);
                                    dots.forEach((d, i) => d.setAttribute('aria-selected', String(i === aktif)));
                                }
                            }
                        }, { root: track, threshold: 0.6 }).observe(s);
                    });
                }

                // Auto-advance: six seconds, paused under the pointer or keyboard
                // focus, and never for somebody who asked for reduced motion.
                const promo = track.closest('.promo');
                ['mouseenter', 'focusin', 'touchstart'].forEach((ev) => promo.addEventListener(ev, () => { jeda = true; }, { passive: true }));
                ['mouseleave', 'focusout', 'touchend'].forEach((ev) => promo.addEventListener(ev, () => { jeda = false; }, { passive: true }));

                if (jumlah > 1 && ! window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    setInterval(() => { if (! jeda && document.visibilityState === 'visible') ke(aktif + 1); }, 6000);
                }
            })();
        </script>
    @endpush
@endif
