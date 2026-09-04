{{-- Three queues, three conversations. Nothing here settles anything. --}}
<x-filament-panels::page>

    @php($daftar = $this->daftar())

    @php($antrean = [
        ['kunci' => 'janji_hari_ini', 'judul' => 'Janji bayar hari ini',
         'sunyi' => 'Tidak ada janji yang jatuh hari ini.',
         'warna' => 'border-warning-300 dark:border-warning-500/30'],
        ['kunci' => 'janji_meleset', 'judul' => 'Janji meleset',
         'sunyi' => 'Tidak ada janji yang dilanggar. Bagus.',
         'warna' => 'border-danger-300 dark:border-danger-500/30'],
        ['kunci' => 'belum_dihubungi', 'judul' => 'Lewat tempo, belum dihubungi',
         'sunyi' => 'Semua yang lewat tempo sudah pernah dihubungi.',
         'warna' => 'border-gray-200 dark:border-white/10'],
    ])

    @foreach ($antrean as $bagian)
        @php($baris = $daftar[$bagian['kunci']])

        <div class="rounded-xl border bg-white dark:bg-gray-900 {{ $bagian['warna'] }}">
            <div class="flex items-baseline justify-between border-b border-gray-100 px-4 py-3 dark:border-white/5">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ $bagian['judul'] }}
                </h2>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $baris->count() }} faktur</span>
            </div>

            @if ($baris->isEmpty())
                <p class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ $bagian['sunyi'] }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-xs uppercase tracking-wide
                                       text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="px-4 py-2 text-left">Faktur</th>
                                <th class="px-4 py-2 text-left">Pelanggan</th>
                                <th class="px-4 py-2 text-left">Jatuh tempo</th>
                                <th class="px-4 py-2 text-right">Sisa</th>
                                <th class="px-4 py-2 text-left">Kontak terakhir</th>
                                <th class="px-4 py-2 text-right"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($baris as $invoice)
                                @php($janji = $this->janjiUntuk($invoice))
                                @php($terakhir = $this->riwayat($invoice)->first())

                                <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                    <td class="px-4 py-2 font-mono text-xs">{{ $invoice->nomor }}</td>
                                    <td class="px-4 py-2">{{ $invoice->company?->nama }}</td>
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        {{ $invoice->due_date?->format('d/m/Y') }}
                                        @if ($invoice->due_date)
                                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                                ({{ (int) $invoice->due_date->diffInDays(now()) }} hari)
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono whitespace-nowrap">
                                        {{ \App\Domain\Money::format($invoice->amountOutstanding()) }}
                                    </td>
                                    <td class="px-4 py-2 text-xs">
                                        @if ($terakhir)
                                            <span class="text-gray-700 dark:text-gray-200">
                                                {{ $terakhir->hasil->label() }}
                                            </span>
                                            <span class="text-gray-500 dark:text-gray-400">
                                                — {{ $terakhir->user?->name }},
                                                {{ $terakhir->dihubungi_pada->format('d/m/Y') }}
                                            </span>

                                            @if ($janji)
                                                <div class="text-gray-500 dark:text-gray-400">
                                                    Janji {{ $janji->janji_tanggal->format('d/m/Y') }}
                                                    @if ($janji->janji_rupiah)
                                                        — {{ \App\Domain\Money::format((int) $janji->janji_rupiah) }}
                                                    @endif
                                                </div>
                                            @endif

                                            @if ($terakhir->catatan)
                                                <div class="text-gray-500 italic dark:text-gray-400">
                                                    “{{ \Illuminate\Support\Str::limit($terakhir->catatan, 60) }}”
                                                </div>
                                            @endif
                                        @else
                                            <span class="text-gray-400">Belum pernah dihubungi</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        {{ ($this->catatAction)(['invoice' => $invoice->id]) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endforeach

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Janji bayar adalah catatan pembicaraan, bukan pembayaran: sisa tagihan, umur piutang dan
        pembekuan tidak berubah karenanya. Uang tetap masuk lewat pencatatan pembayaran oleh Keuangan,
        dan janji dianggap ditepati hanya bila uangnya benar-benar sampai.
    </p>

</x-filament-panels::page>
