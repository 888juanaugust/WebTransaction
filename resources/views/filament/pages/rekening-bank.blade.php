{{-- The rekening register: label, institution, its GL account, who is default. --}}
<x-filament-panels::page>

    <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs uppercase tracking-wide
                               text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="px-4 py-2 text-left">Label</th>
                        <th class="px-4 py-2 text-left">Bank</th>
                        <th class="px-4 py-2 text-left">Nomor</th>
                        <th class="px-4 py-2 text-left">Atas nama</th>
                        <th class="px-4 py-2 text-left">Akun buku besar</th>
                        <th class="px-4 py-2 text-left"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->daftar() as $rekening)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                            <td class="px-4 py-2 font-medium">
                                {{ $rekening->nama }}
                                @if ($rekening->is_default)
                                    <span class="ms-1 inline-flex rounded-md bg-primary-50 px-2 py-0.5 text-xs
                                                 font-medium text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">
                                        bawaan
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-2">{{ $rekening->bank }}</td>
                            <td class="px-4 py-2 font-mono">{{ $rekening->nomor }}</td>
                            <td class="px-4 py-2">{{ $rekening->atas_nama }}</td>
                            <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ $rekening->account?->kode }} — {{ $rekening->account?->nama }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                @unless ($rekening->is_default)
                                    {{ ($this->jadikanBawaanAction)(['rekening' => $rekening->id]) }}
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="border-t border-gray-100 px-4 py-3 text-sm text-gray-500 dark:border-white/5 dark:text-gray-400">
            Pembayaran pelanggan dan pemasok bisa memilih rekening per transaksi.
            Beban, uang muka, dan pembelian aktiva selalu keluar dari rekening bawaan.
            Rekening yang tercetak di faktur diatur di Pengaturan perusahaan.
        </p>
    </div>

</x-filament-panels::page>
