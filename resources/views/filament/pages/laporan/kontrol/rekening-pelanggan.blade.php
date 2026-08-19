<div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
    <div class="flex flex-wrap items-end gap-4">
        <div class="min-w-64 flex-1">
            <label for="companyId" class="text-sm font-medium text-gray-700 dark:text-gray-200">
                Pelanggan
            </label>
            <select id="companyId" wire:model.live="companyId"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm
                           dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
                <option value="">— pilih pelanggan —</option>
                @foreach ($this->companyOptions() as $id => $nama)
                    <option value="{{ $id }}">{{ $nama }}</option>
                @endforeach
            </select>
        </div>

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

    </div>

    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
        Saldo akhirnya sama dengan angka yang dipakai pemeriksaan kredit dan laporan umur
        piutang. Bilyet giro yang belum cair tidak mengurangi saldo — disebut sebagai catatan
        di bawah tabel.
    </p>
</div>
