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
        Saldo akhirnya adalah jumlah faktur, dikurangi pembayaran dan nota kredit — sama
        dengan laporan umur piutang. Dua hal tidak mengurangi saldo dan hanya disebut sebagai
        catatan di bawah tabel: bilyet giro yang belum cair, dan uang muka yang belum dipakai
        untuk faktur mana pun. Pemeriksaan kredit memakai angka yang sudah dikurangi uang
        muka, karena uang itu sudah ada di tangan kita.
    </p>
</div>
