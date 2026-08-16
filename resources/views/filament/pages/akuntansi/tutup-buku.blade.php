{{--
    Months, newest first, with what each holds and whether it is locked.

    Newest first because that is where the work is: the month you are about to
    close is at the top, and the reopen action — legal only for the most recent
    closed month — sits right under it.
--}}
@php
    use App\Domain\Money;

    $rows = $this->getRows();
    $next = $this->nextToClose();
    $openFrom = $this->openFrom();
    $preview = $this->yearEndPreview();
    $reopenings = $this->getReopenings();

    /*
        Filament renders an action whose visible() is false as a *disabled*
        button rather than omitting it, which reads as "you could do this if
        the month were different" instead of "this is not yours to do". Finance
        never reopens a period, so Finance never sees the control.
    */
    $canReopen = auth()->user()?->role()->canReopenPeriod() ?? false;
@endphp

<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
        @if ($openFrom === null)
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Belum ada periode yang ditutup. Semua tanggal masih menerima jurnal.
            </p>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Buku terkunci sampai
                <strong>{{ $openFrom->copy()->subDay()->translatedFormat('j F Y') }}</strong>.
                Jurnal hanya bisa diposting mulai
                <strong>{{ $openFrom->translatedFormat('j F Y') }}</strong>.
            </p>
        @endif

        @if ($next === null)
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Tidak ada periode yang siap ditutup. Bulan berjalan baru bisa ditutup setelah berakhir.
            </p>
        @endif
    </div>

    @if ($preview)
        <div class="rounded-xl border border-warning-300 bg-warning-50 p-4
                    dark:border-warning-500/30 dark:bg-warning-500/10">
            <h2 class="text-sm font-semibold text-warning-800 dark:text-warning-300">
                Penutupan tahun {{ $preview['tahun'] }}
            </h2>
            <p class="mt-1 text-sm text-warning-800/90 dark:text-warning-300/90">
                Menutup Desember juga menutup tahun buku. Satu jurnal penutup sebesar
                <strong class="font-mono">{{ Money::format($preview['total']) }}</strong>
                atas {{ $preview['baris'] }} akun akan memindahkan seluruh pendapatan dan beban
                {{ $preview['tahun'] }} ke Laba Ditahan, sehingga Januari mulai dari nol.
            </p>
        </div>
    @endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide
                           text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <th class="py-2 pl-4 pr-3">Periode</th>
                    <th class="py-2 px-3">Status</th>
                    <th class="py-2 px-3 text-right">Penjualan</th>
                    <th class="py-2 px-3 text-right">Laba bersih</th>
                    <th class="py-2 px-3">Ditutup oleh</th>
                    <th class="py-2 pr-4 pl-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr @class([
                        'border-b border-gray-100 last:border-0 dark:border-white/5',
                        'bg-primary-50/60 dark:bg-primary-500/10' => $row['closable'],
                    ])>
                        <td class="py-2 pl-4 pr-3 font-medium">
                            {{ $row['label'] }}
                            @if ($row['yearEnd'])
                                <span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600
                                             dark:bg-white/10 dark:text-gray-300">akhir tahun</span>
                            @endif
                        </td>

                        <td class="py-2 px-3">
                            @if ($row['closed'])
                                <span class="inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-0.5 text-xs
                                             font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">
                                    Ditutup
                                </span>
                            @elseif ($row['closable'])
                                <span class="inline-flex items-center gap-1 rounded bg-primary-100 px-2 py-0.5 text-xs
                                             font-medium text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">
                                    Siap ditutup
                                </span>
                            @else
                                <span class="text-xs text-gray-500 dark:text-gray-400">Terbuka</span>
                            @endif
                        </td>

                        <td class="py-2 px-3 text-right font-mono tabular-nums">
                            {{ $row['pendapatan'] === 0 ? '—' : Money::format($row['pendapatan']) }}
                        </td>

                        <td @class([
                            'py-2 px-3 text-right font-mono tabular-nums',
                            'text-danger-600 dark:text-danger-400' => $row['laba'] < 0,
                        ])>
                            {{ $row['laba'] === 0 ? '—' : Money::format($row['laba']) }}
                        </td>

                        <td class="py-2 px-3 text-xs text-gray-500 dark:text-gray-400">
                            @if ($row['closed'])
                                {{ $row['period']->closedBy?->name ?? '—' }}
                                <span class="block">
                                    {{ $row['period']->closed_at?->translatedFormat('j M Y') }}
                                </span>
                                @if ($row['period']->catatan)
                                    <span class="block italic">{{ $row['period']->catatan }}</span>
                                @endif
                            @endif
                        </td>

                        <td class="py-2 pr-4 pl-3 text-right">
                            @if ($row['reopenable'] && $canReopen)
                                {{ ($this->bukaAction)(['tahun' => $row['tanggal']->year, 'bulan' => $row['tanggal']->month]) }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            Belum ada jurnal, jadi belum ada periode untuk ditutup.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($reopenings !== [])
        <section class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <h2 class="border-b border-gray-200 px-4 py-3 text-xs font-semibold uppercase tracking-wide
                       text-gray-500 dark:border-white/10 dark:text-gray-400">
                Periode yang pernah dibuka kembali
            </h2>
            <table class="w-full text-sm">
                <tbody>
                    @foreach ($reopenings as $reopening)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                            <td class="py-2 pl-4 pr-3 font-medium">{{ $reopening->label() }}</td>
                            <td class="py-2 px-3 text-gray-600 dark:text-gray-300">{{ $reopening->alasan }}</td>
                            <td class="py-2 pr-4 pl-3 text-right text-xs text-gray-500 dark:text-gray-400">
                                {{ $reopening->reopenedBy?->name }}
                                — {{ $reopening->created_at?->translatedFormat('j M Y') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
