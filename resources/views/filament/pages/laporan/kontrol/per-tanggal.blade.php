<div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
    <label for="perTanggal" class="text-sm font-medium text-gray-700 dark:text-gray-200">Per tanggal</label>
    <input id="perTanggal" type="date" wire:model.live="perTanggal"
           class="mt-1 block rounded-lg border-gray-300 text-sm shadow-sm
                  dark:border-white/10 dark:bg-gray-800 dark:text-gray-100" />

    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
        Umur dihitung dari jatuh tempo faktur. Totalnya harus sama dengan Piutang Usaha di
        buku besar — kalau tidak, ada peringatan di atas tabel.
    </p>
</div>
