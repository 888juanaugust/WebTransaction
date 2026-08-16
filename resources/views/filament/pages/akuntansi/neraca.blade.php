{{--
    Neraca as at one date.

    Assets on the left of the eye, liabilities and equity beneath — the
    stacked form rather than the two-column T, because the page has to work on
    a laptop and a phone and a T-account does neither well below 900px.

    The equity block carries a line with no account behind it. See BalanceSheet.
--}}
@php
    use App\Domain\Money;

    $neraca = $this->getNeraca();
@endphp

<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-4">
        <label class="text-sm">
            <span class="mb-1 block font-medium text-gray-700 dark:text-gray-200">Per tanggal</span>
            <input
                type="date"
                wire:model.live="tanggal"
                class="fi-input block rounded-lg border-gray-300 bg-white text-sm shadow-sm
                       dark:border-white/10 dark:bg-white/5 dark:text-white"
            >
        </label>

        <p class="text-sm text-gray-500 dark:text-gray-400">
            Posisi keuangan pada {{ $neraca->asOf->translatedFormat('j F Y') }}.
        </p>
    </div>

    @unless ($neraca->isBalanced())
        <div class="rounded-lg border border-danger-300 bg-danger-50 p-4 text-sm
                    dark:border-danger-500/30 dark:bg-danger-500/10">
            <p class="font-semibold text-danger-700 dark:text-danger-400">
                Neraca tidak seimbang — selisih {{ Money::format($neraca->selisih()) }}.
            </p>
            <p class="mt-1 text-danger-700/80 dark:text-danger-400/80">
                Ini seharusnya tidak mungkin terjadi: setiap jurnal ditolak kalau debit tidak sama
                dengan kredit. Periksa apakah ada yang menulis langsung ke tabel jurnal.
            </p>
        </div>
    @endunless

    <div class="grid gap-6 lg:grid-cols-2">
        <x-akuntansi.bagian :section="$neraca->aset()" />

        <div class="space-y-6">
            <x-akuntansi.bagian :section="$neraca->kewajiban()" />
            <x-akuntansi.bagian :section="$neraca->modal()" />
        </div>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
        <dl class="grid gap-3 sm:grid-cols-2">
            <div class="flex items-baseline justify-between gap-4">
                <dt class="text-sm font-medium text-gray-600 dark:text-gray-300">Total aset</dt>
                <dd class="font-mono text-lg font-semibold tabular-nums">
                    {{ Money::format($neraca->totalAset()) }}
                </dd>
            </div>
            <div class="flex items-baseline justify-between gap-4">
                <dt class="text-sm font-medium text-gray-600 dark:text-gray-300">
                    Total kewajiban dan modal
                </dt>
                <dd class="font-mono text-lg font-semibold tabular-nums">
                    {{ Money::format($neraca->totalKewajibanDanModal()) }}
                </dd>
            </div>
        </dl>
    </div>

    @if ($neraca->labaTahunBerjalan !== 0 || $neraca->labaDitahanBelumDitutup !== 0)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Laba belum ditutup ke Laba Ditahan — belum ada proses tutup buku, jadi hasil usaha
            ditampilkan sebagai baris tersendiri di bagian modal.
        </p>
    @endif
</x-filament-panels::page>
