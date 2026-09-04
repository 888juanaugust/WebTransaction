{{-- Upload, look, then decide. Nothing here writes until the button below does. --}}
<x-filament-panels::page>

    @if ($this->galat)
        <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 text-sm text-danger-800
                    dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-300">
            {{ $this->galat }}
        </div>
    @endif

    @if ($this->rows() === [])
        {{-- Nothing uploaded yet: explain the format rather than showing an empty table. --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Isi berkasnya</h2>

            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Unduh contoh CSV di atas, isi barisnya, lalu unggah. Baris judulnya jangan diubah —
                itu yang dibaca sistem. Kode yang sudah ada akan <strong>memperbarui</strong>
                pelanggan itu, bukan membuat yang kedua.
            </p>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs uppercase tracking-wide
                                   text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="px-2 py-2 text-left">Kolom</th>
                            <th class="px-2 py-2 text-left">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->keterangan() as $kolom => $arti)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                <td class="px-2 py-1.5 font-mono text-xs whitespace-nowrap">{{ $kolom }}</td>
                                <td class="px-2 py-1.5 text-gray-600 dark:text-gray-300">{{ $arti }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        @php($ringkasan = $this->ringkasan())

        <div class="flex flex-wrap items-center gap-3">
            <span class="rounded-lg bg-success-50 px-3 py-1.5 text-sm font-medium text-success-700
                         dark:bg-success-500/10 dark:text-success-300">
                {{ $ringkasan['baru'] }} baru
            </span>
            <span class="rounded-lg bg-primary-50 px-3 py-1.5 text-sm font-medium text-primary-700
                         dark:bg-primary-500/10 dark:text-primary-300">
                {{ $ringkasan['perbarui'] }} diperbarui
            </span>
            <span class="rounded-lg bg-danger-50 px-3 py-1.5 text-sm font-medium text-danger-700
                         dark:bg-danger-500/10 dark:text-danger-300">
                {{ $ringkasan['tertahan'] }} tertahan
            </span>

            <span class="text-sm text-gray-500 dark:text-gray-400">dari {{ $this->namaBerkas }}</span>

            <div class="ms-auto">
                {{ $this->imporAction }}
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs uppercase tracking-wide
                                   text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="px-3 py-2 text-right">Baris</th>
                            <th class="px-3 py-2 text-left">Kode</th>
                            <th class="px-3 py-2 text-left">Nama</th>
                            <th class="px-3 py-2 text-left">Hasil</th>
                            <th class="px-3 py-2 text-left">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->rows() as $row)
                            <tr @class([
                                'border-b border-gray-100 last:border-0 dark:border-white/5',
                                'bg-danger-50/50 dark:bg-danger-500/5' => $row->tertahan(),
                            ])>
                                <td class="px-3 py-2 text-right font-mono text-xs text-gray-500">{{ $row->baris }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row->kode ?: '—' }}</td>
                                <td class="px-3 py-2">{{ $row->nama ?: '—' }}</td>
                                <td class="px-3 py-2">
                                    <span @class([
                                        'inline-flex rounded-md px-2 py-0.5 text-xs font-medium',
                                        'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-300' => $row->status === \App\Domain\Import\CompanyImportRow::BARU,
                                        'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300' => $row->status === \App\Domain\Import\CompanyImportRow::PERBARUI,
                                        'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-300' => $row->tertahan(),
                                    ])>
                                        {{ $row->statusLabel() }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-300">
                                    @foreach ($row->alasan as $alasan)
                                        <div class="text-danger-700 dark:text-danger-400">{{ $alasan }}</div>
                                    @endforeach
                                    @foreach ($row->catatan as $catatan)
                                        <div class="text-gray-500 dark:text-gray-400">{{ $catatan }}</div>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="border-t border-gray-100 px-3 py-2 text-xs text-gray-500 dark:border-white/5 dark:text-gray-400">
                Baris yang tertahan tidak akan disimpan. Perbaiki di berkasnya lalu unggah ulang —
                yang sudah tersimpan tidak akan terduplikasi, karena kode yang sama memperbarui.
            </p>
        </div>
    @endif

</x-filament-panels::page>
