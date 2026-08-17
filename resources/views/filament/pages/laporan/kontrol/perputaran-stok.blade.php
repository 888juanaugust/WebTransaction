<div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
    <label for="nilaiMinimal" class="text-sm font-medium text-gray-700 dark:text-gray-200">
        Sembunyikan barang bernilai di bawah
    </label>
    <input id="nilaiMinimal" type="number" min="0" step="100000" wire:model.live.debounce.500ms="nilaiMinimal"
           class="mt-1 block w-48 rounded-lg border-gray-300 text-sm shadow-sm
                  dark:border-white/10 dark:bg-gray-800 dark:text-gray-100" />

    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
        Urutan daftar adalah sarannya: yang belum pernah terjual di atas, lalu yang paling
        lambat. Di situ uangnya mengendap.
    </p>
</div>
