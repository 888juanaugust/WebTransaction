{{-- Period and grouping. Live-updating, because the point of the grouping
     switch is to try another one immediately. --}}
<div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
    <div class="flex flex-wrap items-end gap-4">
        <div>
            <label for="dari" class="text-sm font-medium text-gray-700 dark:text-gray-200">Dari</label>
            <input id="dari" type="date" wire:model.live="dari"
                   class="mt-1 block rounded-lg border-gray-300 text-sm shadow-sm
                          dark:border-white/10 dark:bg-gray-800 dark:text-gray-100" />
        </div>

        <div>
            <label for="sampai" class="text-sm font-medium text-gray-700 dark:text-gray-200">Sampai</label>
            <input id="sampai" type="date" wire:model.live="sampai"
                   class="mt-1 block rounded-lg border-gray-300 text-sm shadow-sm
                          dark:border-white/10 dark:bg-gray-800 dark:text-gray-100" />
        </div>

        <div>
            <label for="dimensi" class="text-sm font-medium text-gray-700 dark:text-gray-200">Dikelompokkan per</label>
            <select id="dimensi" wire:model.live="dimensi"
                    class="mt-1 block rounded-lg border-gray-300 text-sm shadow-sm
                           dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
                @foreach ($this->getDimensiOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
        {{ $this->getDimensi()->question() }}
        Penjualan dihitung dari faktur yang terbit pada periode ini, dikurangi nota kredit —
        sama dengan angka Penjualan di buku besar.
    </p>
</div>
