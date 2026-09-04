{{-- Money in that nobody has finished accounting for. --}}
<x-filament-panels::page>

    @php($antrean = $this->antrean())

    <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <div class="flex items-baseline justify-between border-b border-gray-100 px-4 py-3 dark:border-white/5">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                Belum dicocokkan seluruhnya
            </h2>
            <span class="text-sm text-gray-500 dark:text-gray-400">
                {{ $antrean->count() }} penerimaan
            </span>
        </div>

        @if ($antrean->isEmpty())
            <p class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                Semua uang yang masuk sudah dicocokkan ke faktur.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs uppercase tracking-wide
                                   text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="px-4 py-2 text-left">Tanggal</th>
                            <th class="px-4 py-2 text-left">Pelanggan</th>
                            <th class="px-4 py-2 text-right">Diterima</th>
                            <th class="px-4 py-2 text-right">Belum dipakai</th>
                            <th class="px-4 py-2 text-left">Rincian</th>
                            <th class="px-4 py-2 text-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($antrean as $entry)
                            <tr class="border-b border-gray-100 align-top last:border-0 dark:border-white/5">
                                <td class="px-4 py-2 whitespace-nowrap">
                                    {{ $entry->paid_at?->format('d/m/Y') }}
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $entry->bankAccount?->label() ?? '—' }}
                                    </div>
                                </td>

                                <td class="px-4 py-2">
                                    {{ $entry->company?->nama }}
                                    @if ($entry->catatan)
                                        <div class="text-xs text-gray-500 italic dark:text-gray-400">
                                            “{{ \Illuminate\Support\Str::limit($entry->catatan, 50) }}”
                                        </div>
                                    @endif
                                </td>

                                <td class="px-4 py-2 text-right font-mono whitespace-nowrap">
                                    {{ \App\Domain\Money::format((int) $entry->amount_rupiah) }}
                                </td>

                                <td class="px-4 py-2 text-right font-mono font-semibold whitespace-nowrap
                                           text-warning-600 dark:text-warning-400">
                                    {{ \App\Domain\Money::format($this->sisa($entry)) }}
                                </td>

                                <td class="px-4 py-2 text-xs">
                                    @php($rincian = $this->rincian($entry))

                                    @if ($rincian->isEmpty())
                                        <span class="text-gray-400">Belum dicocokkan ke faktur mana pun</span>
                                    @else
                                        @foreach ($rincian as $alokasi)
                                            <div class="flex items-center gap-2 py-0.5">
                                                <span @class([
                                                    'font-mono',
                                                    'text-gray-400 line-through' => $alokasi->amount_rupiah < 0,
                                                ])>
                                                    {{ $alokasi->invoice?->nomor }}
                                                </span>
                                                <span class="text-gray-500 dark:text-gray-400">
                                                    {{ \App\Domain\Money::format((int) $alokasi->amount_rupiah) }}
                                                </span>

                                                {{-- Only a standing application can be taken back. --}}
                                                @if ($alokasi->amount_rupiah > 0
                                                    && ! $rincian->contains(fn ($x) => (int) $x->reverses_allocation_id === (int) $alokasi->id))
                                                    {{ ($this->batalkanAction)(['alokasi' => $alokasi->id]) }}
                                                @endif
                                            </div>
                                        @endforeach
                                    @endif
                                </td>

                                <td class="px-4 py-2 text-right">
                                    {{ ($this->cocokkanAction)(['entry' => $entry->id]) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Satu penerimaan adalah satu baris di rekening koran, apa pun jumlah faktur yang dilunasinya —
        itulah yang membuat rekonsiliasi bank bisa dicocokkan satu-satu. Pencocokan ke faktur dicatat
        terpisah dan tidak pernah dihapus: membatalkan berarti menambah baris negatif, sehingga
        keputusan lama tetap terbaca kalau pelanggan menanyakannya bulan depan.
    </p>

</x-filament-panels::page>
