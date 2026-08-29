<div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
    <label for="bulan" class="text-sm font-medium text-gray-700 dark:text-gray-200">Bulan</label>
    <input id="bulan" type="month" wire:model.live="bulan"
           class="mt-1 block rounded-lg border-gray-300 text-sm shadow-sm
                  dark:border-white/10 dark:bg-gray-800 dark:text-gray-100" />

    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
        {{ $this->keteranganBulan() }}
    </p>
</div>
