{{--
    The cookie and location notice.

    Honest about what it is: this site sets technical cookies only — the
    session, the CSRF token, and the language choice a visitor makes on
    purpose — and asks for location only when somebody presses the button on
    the contact page. There is nothing to opt out of, so the one control is
    "Mengerti"; the notice exists because the owner wanted visitors told, and
    a notice that pretends there is a choice where there is none teaches
    people to click through notices.

    Remembered in localStorage rather than a cookie, because a cookie whose
    only job is to say "you have seen the cookie notice" would itself need
    naming in the privacy policy. Shown only once JavaScript has confirmed
    the visitor has not already acknowledged it, so it never flashes for
    somebody who has.
--}}
<div id="persetujuan" hidden
     class="fixed inset-x-4 bottom-4 z-50 mx-auto max-w-3xl rounded-panel border border-line bg-white p-5 shadow-panel sm:p-6"
     role="region" aria-label="{{ __('publik.persetujuan.judul') }}">
    <p class="text-sm font-semibold text-ink">{{ __('publik.persetujuan.judul') }}</p>
    <p class="mt-1.5 text-sm leading-relaxed text-ink-muted">{{ __('publik.persetujuan.isi') }}</p>
    <p class="mt-1 text-sm leading-relaxed text-ink-muted">{{ __('publik.persetujuan.lokasi') }}</p>

    <div class="mt-4 flex flex-wrap items-center gap-2">
        <button type="button" id="persetujuan-mengerti"
                class="rounded-btn bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-btn transition hover:bg-brand-500">
            {{ __('publik.persetujuan.mengerti') }}
        </button>
        <a href="{{ route('publik.kontak') }}#cabang"
           class="rounded-btn border border-line-strong bg-white px-4 py-2 text-sm font-semibold text-ink transition hover:bg-ground-2">
            {{ __('publik.persetujuan.izinkan_lokasi') }}
        </a>
        <a href="{{ route('publik.privasi') }}#cookie" class="ml-auto text-sm font-medium text-brand-600 hover:text-brand-700">
            {{ __('publik.persetujuan.baca') }}
        </a>
    </div>
</div>

@push('kaki')
    <script nonce="{{ $cspNonce ?? '' }}">
        (() => {
            const KUNCI = 'wt-persetujuan';
            const kotak = document.getElementById('persetujuan');
            if (! kotak) return;

            let sudah = false;
            try { sudah = localStorage.getItem(KUNCI) === '1'; } catch (e) { /* private mode: show it */ }

            if (! sudah) kotak.hidden = false;

            document.getElementById('persetujuan-mengerti')?.addEventListener('click', () => {
                try { localStorage.setItem(KUNCI, '1'); } catch (e) { /* fine */ }
                kotak.hidden = true;
            });
        })();
    </script>
@endpush
