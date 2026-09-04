{{-- Pick a dataset, arrange it, keep the arrangement. Rows only — no sums. --}}
<x-filament-panels::page>

    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label for="dataset" class="text-sm font-medium text-gray-700 dark:text-gray-200">
                    Kumpulan data
                </label>
                <select id="dataset" wire:model.live="dataset"
                        class="mt-1 block rounded-lg border-gray-300 text-sm shadow-sm
                               dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
                    @foreach ($this->pilihanDataset() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <p class="pb-1 text-sm text-gray-500 dark:text-gray-400">
                {{ $this->kumpulan()->keterangan() }}
            </p>
        </div>

        @php($tersimpan = $this->tampilanTersimpan())

        @if ($tersimpan->isNotEmpty())
            <div class="mt-4 border-t border-gray-100 pt-3 dark:border-white/5">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Filter tersimpan
                </p>

                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($tersimpan as $view)
                        <span class="inline-flex items-center gap-2 rounded-lg border border-gray-200
                                     bg-gray-50 px-2 py-1 text-sm dark:border-white/10 dark:bg-white/5">
                            <span class="font-medium">{{ $view->nama }}</span>

                            @if (! $view->milik((int) auth()->id()))
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    dari {{ $view->user?->name }}
                                </span>
                            @elseif ($view->dibagikan)
                                <span class="rounded bg-primary-50 px-1.5 text-xs text-primary-700
                                             dark:bg-primary-500/20 dark:text-primary-300">dibagikan</span>
                            @endif

                            {{ ($this->pakaiAction)(['view' => $view->id]) }}

                            @if ($view->milik((int) auth()->id()))
                                {{ ($this->hapusTampilanAction)(['view' => $view->id]) }}
                            @endif
                        </span>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{ $this->table }}

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Layar ini menampilkan baris apa adanya — tanpa penjumlahan dan tanpa angka turunan.
        Untuk total dan margin, pakai laporan di menu yang sama. Unduhan CSV mengikuti filter,
        urutan dan kolom yang sedang tampil.
    </p>

</x-filament-panels::page>
