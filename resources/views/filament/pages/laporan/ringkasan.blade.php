{{--
    One month on one sheet. Flow figures belong to the chosen month; the
    position figures are today's, and each card's caption says which.
    Printable: the print rules hide the panel chrome so the sheet that
    comes out of the printer is just the figures.
--}}
@php
    use App\Domain\Money;

    $r = $this->ringkasan();
@endphp

<x-filament-panels::page>

    <style media="print">
        .fi-sidebar, .fi-topbar, .fi-header-actions, #kontrol-ringkasan { display: none !important; }
        .fi-main { padding: 0 !important; }
    </style>

    <div id="kontrol-ringkasan" class="flex flex-wrap items-end justify-between gap-4">
        <div class="w-56">
            <label for="bulan" class="text-sm font-medium text-gray-700 dark:text-gray-300">Bulan</label>
            <select id="bulan" wire:model.live="bulan"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm
                           focus:border-primary-500 focus:ring-primary-500
                           dark:border-white/10 dark:bg-gray-900 dark:text-gray-100">
                @foreach ($this->pilihanBulan() as $nilai => $label)
                    <option value="{{ $nilai }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <button type="button" onclick="window.print()"
                class="rounded-lg bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm
                       ring-1 ring-gray-950/10 transition hover:bg-gray-50
                       dark:bg-gray-900 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/5">
            Cetak
        </button>
    </div>

    {{-- The month's flow. --}}
    <div>
        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ $r->period->label }}
        </h2>

        <dl class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach (array_filter([
                ['Penjualan', Money::format($r->penjualan), $r->faktur.' faktur'],
                $r->margin !== null
                    ? ['Margin', Money::format($r->margin), $r->marginPersen !== null ? $r->marginPersen.'%' : null]
                    : null,
                ['Uang masuk', Money::format($r->uangMasuk), 'buku pembayaran, bersih dari pembalikan'],
                ['Piutang saat ini', Money::format($r->piutang), 'posisi hari ini, bukan akhir bulan terpilih'],
            ]) as [$label, $nilai, $keterangan])
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                    <dd class="mt-1 text-2xl font-bold tracking-tight text-gray-900 dark:text-gray-50">{{ $nilai }}</dd>
                    @if ($keterangan)
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $keterangan }}</p>
                    @endif
                </div>
            @endforeach
        </dl>

        @foreach ($r->catatan as $catatan)
            <p class="mt-3 text-xs text-amber-700 dark:text-amber-400">⚠ {{ $catatan }}</p>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Who mattered this month. --}}
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="border-b border-gray-950/5 px-5 py-3 text-sm font-semibold text-gray-900 dark:border-white/10 dark:text-gray-100">
                Pelanggan terbesar
            </h3>
            @forelse ($r->topPelanggan as $baris)
                <div class="flex items-baseline justify-between gap-4 px-5 py-2.5 text-sm
                            {{ ! $loop->last ? 'border-b border-gray-950/5 dark:border-white/10' : '' }}">
                    <span class="min-w-0 truncate text-gray-700 dark:text-gray-300">{{ $baris['dimensi'] }}</span>
                    <span class="shrink-0 font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                        {{ Money::format($baris['penjualan']) }}
                    </span>
                </div>
            @empty
                <p class="px-5 py-6 text-sm text-gray-500 dark:text-gray-400">Tidak ada penjualan pada bulan ini.</p>
            @endforelse
        </div>

        {{-- What sold. --}}
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="border-b border-gray-950/5 px-5 py-3 text-sm font-semibold text-gray-900 dark:border-white/10 dark:text-gray-100">
                Merk terbesar
            </h3>
            @forelse ($r->topMerk as $baris)
                <div class="flex items-baseline justify-between gap-4 px-5 py-2.5 text-sm
                            {{ ! $loop->last ? 'border-b border-gray-950/5 dark:border-white/10' : '' }}">
                    <span class="min-w-0 truncate text-gray-700 dark:text-gray-300">{{ $baris['dimensi'] }}</span>
                    <span class="shrink-0 font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                        {{ Money::format($baris['penjualan']) }}
                    </span>
                </div>
            @empty
                <p class="px-5 py-6 text-sm text-gray-500 dark:text-gray-400">Tidak ada penjualan pada bulan ini.</p>
            @endforelse
        </div>
    </div>

    {{-- Where the receivable sits, graded by age — today's position. --}}
    <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h3 class="border-b border-gray-950/5 px-5 py-3 text-sm font-semibold text-gray-900 dark:border-white/10 dark:text-gray-100">
            Umur piutang — posisi hari ini
        </h3>
        <div class="grid gap-px overflow-hidden sm:grid-cols-4 lg:grid-cols-7">
            @foreach ($r->umurPiutang as $bucket)
                <div class="px-4 py-3">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $bucket['label'] }}</p>
                    <p @class([
                        'mt-1 text-sm font-semibold tabular-nums',
                        'text-gray-900 dark:text-gray-100' => $bucket['nilai'] >= 0,
                        'text-gray-500 dark:text-gray-400' => $bucket['nilai'] < 0,
                    ])>
                        {{ Money::format($bucket['nilai']) }}
                    </p>
                </div>
            @endforeach
        </div>
        <p class="border-t border-gray-950/5 px-5 py-3 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
            Nilai minus (pembayaran belum dicocokkan, giro di tangan) mengurangi saldo:
            jumlah ketujuh kolom = piutang saat ini. Rinciannya per pelanggan ada di
            Laporan → Umur piutang.
        </p>
    </div>

</x-filament-panels::page>
