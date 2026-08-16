{{--
    Laba rugi for a period.

    Gross profit gets its own row between cost of sales and operating expense,
    because that separation is the only reason to split two kinds of `beban` in
    the first place — margin is the number that says whether the trade works,
    and overheads are a different question.
--}}
@php
    use App\Domain\Money;

    $pl = $this->getLabaRugi();
    $margin = $pl->marginKotorBps();
@endphp

<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-4">
        <label class="text-sm">
            <span class="mb-1 block font-medium text-gray-700 dark:text-gray-200">Dari</span>
            <input type="date" wire:model.live="dari"
                   class="fi-input block rounded-lg border-gray-300 bg-white text-sm shadow-sm
                          dark:border-white/10 dark:bg-white/5 dark:text-white">
        </label>

        <label class="text-sm">
            <span class="mb-1 block font-medium text-gray-700 dark:text-gray-200">Sampai</span>
            <input type="date" wire:model.live="sampai"
                   class="fi-input block rounded-lg border-gray-300 bg-white text-sm shadow-sm
                          dark:border-white/10 dark:bg-white/5 dark:text-white">
        </label>

        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ $pl->from->translatedFormat('j F Y') }} — {{ $pl->to->translatedFormat('j F Y') }}
        </p>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-akuntansi.angka label="Penjualan" :amount="$pl->totalPendapatan()" />
        <x-akuntansi.angka label="Laba kotor" :amount="$pl->labaKotor()"
            :note="$margin === null ? 'Belum ada penjualan' : 'Margin ' . number_format($margin / 100, 2, ',', '.') . '%'" />
        <x-akuntansi.angka label="Laba bersih" :amount="$pl->labaBersih()" emphasis />
    </div>

    <div class="space-y-6">
        <x-akuntansi.bagian :section="$pl->pendapatan()" />
        <x-akuntansi.bagian :section="$pl->hargaPokok()" />

        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-baseline justify-between gap-4">
                <span class="text-sm font-semibold">Laba kotor</span>
                <span @class([
                    'font-mono text-base font-semibold tabular-nums',
                    'text-danger-600 dark:text-danger-400' => $pl->labaKotor() < 0,
                ])>{{ Money::format($pl->labaKotor()) }}</span>
            </div>
        </div>

        <x-akuntansi.bagian :section="$pl->beban()" />

        <div class="rounded-xl border-2 border-primary-200 bg-primary-50 px-4 py-3
                    dark:border-primary-500/30 dark:bg-primary-500/10">
            <div class="flex items-baseline justify-between gap-4">
                <span class="text-sm font-semibold">
                    {{ $pl->labaBersih() < 0 ? 'Rugi bersih' : 'Laba bersih' }}
                </span>
                <span @class([
                    'font-mono text-lg font-semibold tabular-nums',
                    'text-danger-600 dark:text-danger-400' => $pl->labaBersih() < 0,
                ])>{{ Money::format($pl->labaBersih()) }}</span>
            </div>
        </div>
    </div>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Penjualan dicatat saat faktur terbit, sedangkan harga pokok dicatat saat barang dikirim.
        Pesanan yang sudah difakturkan tapi belum dikirim membuat margin periode ini tampak lebih
        tinggi dari yang sebenarnya — lihat catatan kebijakan di <code>docs/MAP.md</code>.
    </p>
</x-filament-panels::page>
