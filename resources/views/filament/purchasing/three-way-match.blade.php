{{--
    Ordered against received against billed, for one purchase order.

    Values exclude PPN — they are what each document says the goods are worth,
    so the three columns are directly comparable. Only the rows that disagree
    are worth a person's attention, so those are the ones that get colour.
--}}
@php use App\Domain\Money; @endphp

<div class="space-y-4">
    @if ($lines === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">PO ini belum punya baris.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide
                               text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Kode</th>
                        <th class="py-2 px-3 text-right">Dipesan</th>
                        <th class="py-2 px-3 text-right">Diterima</th>
                        <th class="py-2 px-3 text-right">Ditagih</th>
                        <th class="py-2 px-3 text-right">Nilai diterima</th>
                        <th class="py-2 px-3 text-right">Nilai ditagih</th>
                        <th class="py-2 pl-3">Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        <tr @class([
                            'border-b border-gray-100 dark:border-white/5',
                            'bg-danger-50 dark:bg-danger-500/10' => $line->hasVariance(),
                        ])>
                            <td class="py-2 pr-3 font-mono">{{ $line->sku }}</td>
                            <td class="py-2 px-3 text-right">{{ number_format($line->orderedQty, 0, ',', '.') }}</td>
                            <td class="py-2 px-3 text-right">{{ number_format($line->receivedQty, 0, ',', '.') }}</td>
                            <td class="py-2 px-3 text-right">{{ number_format($line->billedQty, 0, ',', '.') }}</td>
                            <td class="py-2 px-3 text-right">{{ Money::format($line->receivedValue) }}</td>
                            <td class="py-2 px-3 text-right">{{ Money::format($line->billedValue) }}</td>
                            <td class="py-2 pl-3">
                                @php
                                    $notes = [];
                                    if ($line->isOverBilled()) {
                                        $notes[] = 'Ditagih '.number_format(-$line->unbilledQty(), 0, ',', '.')
                                            .' lebih banyak dari yang diterima';
                                    }
                                    if ($line->isOverReceived()) {
                                        $notes[] = 'Diterima '.number_format(-$line->quantityGap(), 0, ',', '.')
                                            .' lebih banyak dari yang dipesan';
                                    }
                                    if ($line->priceVariance() !== 0) {
                                        $notes[] = 'Selisih harga '.Money::format($line->priceVariance());
                                    }
                                    if ($notes === [] && $line->quantityGap() > 0) {
                                        $notes[] = 'Belum lengkap: kurang '
                                            .number_format($line->quantityGap(), 0, ',', '.');
                                    }
                                @endphp

                                @if ($notes === [])
                                    <span class="text-success-600 dark:text-success-400">Cocok</span>
                                @else
                                    <span class="text-danger-600 dark:text-danger-400">
                                        {{ implode(' · ', $notes) }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{--
            Said out loud, because somebody will otherwise assume the system
            corrected it. Goods are valued at what the receipt said they cost;
            a bill that disagrees is surfaced here and nowhere else.
        --}}
        <p class="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
            Selisih harga <strong>tidak</strong> menyesuaikan nilai persediaan. Barang dinilai
            sebesar harga pada penerimaan barang; bila faktur pemasok berbeda, selisihnya
            ditampilkan di sini untuk ditindaklanjuti, bukan dibukukan otomatis.
        </p>
    @endif
</div>
