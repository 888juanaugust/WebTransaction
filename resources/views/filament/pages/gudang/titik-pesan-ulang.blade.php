{{--
    The buying morning.

    Grouped by supplier because that is the shape of the decision: nobody
    places one order for one part. Each group ends in the button that turns it
    into a draft, so the list does not have to be retyped anywhere.
--}}
@php
    $groups = $this->bySupplier();
    $habis = $this->habisCount();
    $total = array_sum(array_map(fn (array $g) => count($g['rows']), $groups));
@endphp

<x-filament-panels::page>

    @if ($total === 0)
        <div class="rounded-xl border border-gray-200 bg-white p-8 text-center
                    dark:border-white/10 dark:bg-gray-900">
            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                Tidak ada yang perlu dipesan sekarang.
            </p>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Semua barang yang pernah terjual masih di atas titik pesan ulangnya,
                terhitung stok yang sudah dipesan tapi belum datang.
            </p>
        </div>
    @else
        {{-- The count that decides whether today is urgent. Out of stock is a
             customer being turned away this morning; under the point is a
             decision that can wait until this afternoon. --}}
        <div @class([
            'rounded-xl border p-4 text-sm',
            'border-danger-300 bg-danger-50 text-danger-800 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-300'
                => $habis > 0,
            'border-warning-300 bg-warning-50 text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300'
                => $habis === 0,
        ])>
            @if ($habis > 0)
                <strong>{{ $habis }} barang sudah habis</strong> — tidak ada yang bisa dijual
                hari ini. {{ $total }} barang total di bawah titik pesan ulang.
            @else
                <strong>{{ $total }} barang</strong> di bawah titik pesan ulang. Belum ada
                yang habis.
            @endif
        </div>

        @foreach ($groups as $key => $group)
            <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b
                            border-black/5 px-4 py-3 dark:border-white/10">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                            {{ $group['nama'] }}
                        </h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ count($group['rows']) }} barang
                        </p>
                    </div>

                    @if ($group['supplier_id'] !== null)
                        {{ ($this->buatDraftAction)(['supplier' => $group['supplier_id']]) }}
                    @else
                        {{-- No button, and a reason rather than a blank space.
                             Nothing here can pick a supplier for a part we have
                             never bought. --}}
                        <p class="max-w-md text-xs text-gray-500 dark:text-gray-400">
                            Belum pernah ada penerimaan barang untuk barang-barang ini, jadi
                            sistem tidak tahu harus pesan ke siapa. Buat pesanan pembelian
                            secara manual.
                        </p>
                    @endif
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <tr class="border-b border-black/5 dark:border-white/10">
                                <th class="px-4 py-2 text-left font-medium">Barang</th>
                                <th class="px-4 py-2 text-right font-medium">Di gudang</th>
                                <th class="px-4 py-2 text-right font-medium">Dipesan</th>
                                <th class="px-4 py-2 text-right font-medium">Posisi</th>
                                <th class="px-4 py-2 text-right font-medium">Titik</th>
                                <th class="px-4 py-2 text-right font-medium">Cukup</th>
                                <th class="px-4 py-2 text-right font-medium">Saran pesan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($group['rows'] as $row)
                                <tr class="border-b border-black/5 last:border-0 dark:border-white/10">
                                    <td class="px-4 py-2">
                                        <span class="font-medium text-gray-900 dark:text-gray-100">
                                            {{ $row->sku }}
                                        </span>
                                        @if ($row->isHabis())
                                            <span class="ml-1 rounded bg-danger-100 px-1.5 py-0.5 text-xs
                                                         font-medium text-danger-700
                                                         dark:bg-danger-500/20 dark:text-danger-300">
                                                habis
                                            </span>
                                        @endif
                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ $row->nama }}
                                        </div>
                                    </td>

                                    <td class="px-4 py-2 text-right tabular-nums">
                                        {{ number_format($row->onHand, 0, ',', '.') }}
                                        @if ($row->reserved > 0)
                                            {{-- Reserved stock is going to leave. Saying so
                                                 here is what stops "but we have 100 of them". --}}
                                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ number_format($row->reserved, 0, ',', '.') }} dipesan pelanggan
                                            </div>
                                        @endif
                                    </td>

                                    <td class="px-4 py-2 text-right tabular-nums">
                                        {{ $row->onOrder > 0 ? number_format($row->onOrder, 0, ',', '.') : '—' }}
                                    </td>

                                    <td class="px-4 py-2 text-right tabular-nums font-medium">
                                        {{ number_format($row->posisi, 0, ',', '.') }}
                                    </td>

                                    <td class="px-4 py-2 text-right tabular-nums">
                                        {{ number_format($row->titikPesanUlang, 0, ',', '.') }}
                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                            @if ($row->titikManual)
                                                diatur manual
                                            @else
                                                {{ $row->leadTimeHari }} hari kirim
                                                {{ $row->leadTimeTerukur ? '(terukur)' : '(perkiraan)' }}
                                            @endif
                                        </div>
                                    </td>

                                    <td class="px-4 py-2 text-right tabular-nums">
                                        @if ($row->sisaHari() === null)
                                            —
                                        @else
                                            {{ number_format($row->sisaHari(), 1, ',', '.') }} hari
                                        @endif
                                    </td>

                                    <td class="px-4 py-2 text-right tabular-nums font-medium
                                               text-gray-900 dark:text-gray-100">
                                        {{ number_format($row->saranQtyCtn, 0, ',', '.') }} dus
                                        <div class="text-xs font-normal text-gray-500 dark:text-gray-400">
                                            {{ number_format($row->saranQtyBase, 0, ',', '.') }}
                                            {{ $row->satuanDasar }}
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Titik pesan ulang = rata-rata penjualan harian setahun terakhir ×
            (lama kirim + {{ \App\Domain\Stock\ReorderAdvisor::SAFETY_DAYS }} hari aman).
            Posisi = stok di gudang − yang sudah dipesan pelanggan + yang sudah dipesan ke
            pemasok. Saran pesan dibulatkan ke atas ke dus penuh, sampai cukup untuk
            {{ \App\Domain\Stock\ReorderAdvisor::TARGET_COVER_DAYS }} hari ke depan.
        </p>
    @endif

</x-filament-panels::page>
