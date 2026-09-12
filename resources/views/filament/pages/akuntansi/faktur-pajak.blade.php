{{--
    Filing a month's output VAT.

    What is *missing* comes before what is ready, deliberately. A filing that
    quietly leaves an invoice out is PPN we collected and did not report, and
    nobody finds it until the tax office compares our figures with the
    customer's — so the blocked list sits above the button, with the invoice
    number and the fix on every row.
--}}
@php
    use App\Domain\Money;

    $preview = $this->getPreview();
    $exports = $this->getExports();
    $format = config('pajak.format_ekspor');
    $coretax = config('pajak.coretax');
@endphp

<x-filament-panels::page>
    {{--
        The format and the reference codes, on the screen rather than only in
        the code. The person filing is the one who can ask the accountant, and
        they will not read CoretaxXmlWriter or config/pajak.php.
    --}}
    @if ($format === 'coretax_xml')
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                File XML impor Coretax
            </h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                Dibuat dalam format <strong class="font-mono">{{ $format }}</strong> — satu
                <span class="font-mono">TaxInvoice</span> per faktur, satu
                <span class="font-mono">GoodService</span> per baris, mengikuti template dari
                akuntan. Nomor faktur kita ada di <span class="font-mono">RefDesc</span>; nomor
                seri diisi Coretax dan dicatat kembali di sini.
            </p>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Kode rujukan yang dipakai — periksa bersama akuntan sebelum pelaporan pertama:
                negara pembeli <span class="font-mono">{{ $coretax['negara_pembeli'] }}</span>,
                kode barang <span class="font-mono">{{ $coretax['kode_barang'] }}</span>,
                satuan PCS <span class="font-mono">{{ $coretax['satuan']['PCS'] ?? '—' }}</span>,
                satuan SET <span class="font-mono">{{ $coretax['satuan']['SET'] ?? '—' }}</span>.
                Diubah lewat <span class="font-mono">.env</span> (PAJAK_NEGARA_PEMBELI,
                PAJAK_KODE_BARANG, PAJAK_SATUAN_PCS, PAJAK_SATUAN_SET).
            </p>
        </div>
    @else
        <div class="rounded-xl border border-warning-300 bg-warning-50 p-4
                    dark:border-warning-500/30 dark:bg-warning-500/10">
            <h2 class="text-sm font-semibold text-warning-800 dark:text-warning-300">
                Pastikan dulu formatnya
            </h2>
            <p class="mt-1 text-sm text-warning-800/90 dark:text-warning-300/90">
                File dibuat dalam format <strong class="font-mono">{{ $format }}</strong> —
                CSV impor e-Faktur lama. Coretax menerima XML; ganti
                <span class="font-mono">PAJAK_FORMAT_EKSPOR</span> ke
                <span class="font-mono">coretax_xml</span> kecuali akuntan meminta CSV.
            </p>
        </div>
    @endif

    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
        <label for="periode" class="text-sm font-medium text-gray-700 dark:text-gray-200">
            Masa pajak
        </label>
        <select
            id="periode"
            wire:model.live="periode"
            class="mt-1 block w-64 rounded-lg border-gray-300 text-sm shadow-sm
                   dark:border-white/10 dark:bg-gray-800 dark:text-gray-100"
        >
            @foreach ($this->getPeriodeOptions() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>

        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
            Masa pajak mengikuti tanggal faktur, bukan tanggal ekspor.
        </p>
    </div>

    {{-- Blocked first. --}}
    @if ($preview->jumlahTerhalang() > 0)
        <div class="rounded-xl border border-danger-300 bg-danger-50 p-4
                    dark:border-danger-500/30 dark:bg-danger-500/10">
            <h2 class="text-sm font-semibold text-danger-800 dark:text-danger-300">
                {{ $preview->jumlahTerhalang() }} faktur tidak bisa dilaporkan
            </h2>
            <p class="mt-1 text-sm text-danger-800/90 dark:text-danger-300/90">
                Faktur ini akan ditinggalkan kalau file dibuat sekarang. PPN-nya sudah
                dipungut dari pelanggan, jadi meninggalkannya berarti kurang lapor.
            </p>

            {{--
                Grouped by invoice, because one invoice often trips several
                checks at once — a customer with no NPWP usually has no nama
                wajib pajak either. Listing each reason as its own row put the
                same invoice number on screen twice under a heading that said
                "1 faktur", which reads as a system that cannot count.
            --}}
            <ul class="mt-3 space-y-2">
                @foreach (collect($preview->terhalang)->groupBy(fn ($b) => $b->invoice->id) as $group)
                    @php $invoice = $group->first()->invoice; @endphp
                    <li class="rounded-lg bg-white/60 px-3 py-2 text-sm dark:bg-gray-900/40">
                        <span class="font-mono font-medium text-gray-900 dark:text-gray-100">
                            {{ $invoice->nomor }}
                        </span>
                        <span class="text-gray-500 dark:text-gray-400">
                            · {{ $invoice->nama_wajib_pajak ?: $invoice->company?->nama }}
                        </span>
                        @foreach ($group as $blocker)
                            <p class="mt-0.5 text-gray-700 dark:text-gray-300">{{ $blocker->alasan }}</p>
                            <p class="text-gray-500 dark:text-gray-400">{{ $blocker->tindakan }}</p>
                        @endforeach
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Then what would go in. --}}
    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Siap dilaporkan</h2>

                @if ($preview->siap === [])
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        @if ($preview->sudahDiekspor > 0)
                            Semua faktur masa ini sudah pernah diekspor
                            ({{ $preview->sudahDiekspor }} faktur).
                        @else
                            Tidak ada faktur yang siap pada masa pajak ini.
                        @endif
                    </p>
                @else
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        {{ count($preview->siap) }} faktur ·
                        DPP <strong class="font-mono">{{ Money::format($preview->totalDpp()) }}</strong> ·
                        PPN <strong class="font-mono">{{ Money::format($preview->totalPpn()) }}</strong>
                    </p>
                    @if ($preview->sudahDiekspor > 0)
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ $preview->sudahDiekspor }} faktur lain pada masa ini sudah pernah diekspor
                            dan tidak diikutkan lagi.
                        </p>
                    @endif
                @endif
            </div>

            @if ($preview->siap !== [])
                {{ $this->eksporAction }}
            @endif
        </div>

        @if ($preview->siap !== [])
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide
                                   text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-3">Faktur</th>
                            <th class="py-2 px-3">Pembeli</th>
                            <th class="py-2 px-3">NPWP</th>
                            <th class="py-2 px-3 text-right">DPP</th>
                            <th class="py-2 px-3 text-right">PPN</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($preview->siap as $faktur)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                <td class="py-2 pr-3 font-mono">{{ $faktur->referensi }}</td>
                                <td class="py-2 px-3">{{ $faktur->namaWajibPajak }}</td>
                                <td class="py-2 px-3 font-mono text-gray-500 dark:text-gray-400">
                                    {{ $faktur->npwp }}
                                </td>
                                <td class="py-2 px-3 text-right font-mono">
                                    {{ Money::format($faktur->dppRupiah) }}
                                </td>
                                <td class="py-2 px-3 text-right font-mono">
                                    {{ Money::format($faktur->ppnRupiah) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Filings already made for this period, and what came back. --}}
    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Ekspor masa ini</h2>

        @if ($exports === [])
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Belum ada file ekspor untuk masa pajak ini.
            </p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide
                                   text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-3">Nomor</th>
                            <th class="py-2 px-3">Dibuat</th>
                            <th class="py-2 px-3 text-right">Faktur</th>
                            <th class="py-2 px-3 text-right">PPN</th>
                            <th class="py-2 px-3">Nomor seri</th>
                            <th class="py-2 px-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($exports as $export)
                            @php $menunggu = $export->menungguNsfp(); @endphp
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                <td class="py-2 pr-3 font-mono">{{ $export->nomor }}</td>
                                <td class="py-2 px-3 text-gray-500 dark:text-gray-400">
                                    {{ $export->created_at->translatedFormat('j M Y') }}
                                    @if ($export->createdBy)
                                        · {{ $export->createdBy->name }}
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-right font-mono">{{ $export->jumlah_faktur }}</td>
                                <td class="py-2 px-3 text-right font-mono">
                                    {{ Money::format((int) $export->total_ppn_rupiah) }}
                                </td>
                                <td class="py-2 px-3">
                                    @if ($menunggu === 0)
                                        <span class="text-success-600 dark:text-success-400">Lengkap</span>
                                    @else
                                        <span class="text-warning-600 dark:text-warning-400">
                                            {{ $menunggu }} menunggu
                                        </span>
                                    @endif
                                </td>
                                <td class="py-2 px-3">
                                    <div class="flex items-center justify-end gap-2">
                                        <a
                                            href="{{ route('faktur-pajak.unduh', $export) }}"
                                            class="text-primary-600 hover:underline dark:text-primary-400"
                                        >
                                            Unduh
                                        </a>
                                        @if ($menunggu > 0)
                                            {{ ($this->catatNsfpAction)(['export' => $export->id]) }}
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
