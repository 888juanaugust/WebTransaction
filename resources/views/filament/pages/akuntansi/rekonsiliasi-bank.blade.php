{{--
    The reconciliation desk.

    The running difference is at the top and stays there, because it is the
    only number that matters and watching it fall to nil is the whole feedback
    loop. Everything below it exists to move that number.
--}}
@php
    use App\Domain\Money;

    $rec = $this->currentReconciliation();
    $summary = $this->summary();
    $days = $this->daysSince();
@endphp

<x-filament-panels::page>

    @if (! $rec)
        {{-- Nothing in progress. Say how long it has been, because an
             unreconciled bank account is otherwise completely invisible. --}}
        <div @class([
            'rounded-xl border p-4 text-sm',
            'border-warning-300 bg-warning-50 text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300'
                => $days === null || $days > 35,
            'border-gray-200 bg-white text-gray-700 dark:border-white/10 dark:bg-gray-900 dark:text-gray-300'
                => $days !== null && $days <= 35,
        ])>
            @if ($days === null)
                <strong>Rekening bank belum pernah direkonsiliasi.</strong>
                Setiap akun kontrol lain di sistem ini diperiksa terhadap buku pembantu yang
                kita tulis sendiri — tidak ada satu pun yang bisa membuktikan uangnya benar
                ada. Hanya rekening koran yang bisa.
            @elseif ($days === 0)
                {{-- "0 hari yang lalu" is not something anybody says. --}}
                Rekening bank sudah direkonsiliasi <strong>hari ini</strong>.
            @else
                Terakhir direkonsiliasi <strong>{{ $days }} hari</strong> yang lalu.
            @endif
        </div>
    @endif

    @if ($rec && $summary)
        {{-- The statement, computed live. Not stored until it is finalised:
             a stale difference on screen while somebody is working is worse
             than no difference at all. --}}
        <div @class([
            'rounded-xl border',
            'border-success-300 bg-success-50 dark:border-success-500/30 dark:bg-success-500/10'
                => $summary->isReconciled(),
            'border-warning-300 bg-warning-50 dark:border-warning-500/30 dark:bg-warning-500/10'
                => ! $summary->isReconciled(),
        ])>
            <div class="flex flex-wrap items-baseline justify-between gap-2 border-b
                        border-black/5 px-4 py-3 dark:border-white/10">
                <h2 class="text-sm font-semibold">
                    {{ $rec->nomor }} · rekening koran {{ $rec->tanggal_rekening->format('d/m/Y') }}
                </h2>
                <span class="text-sm">
                    {{ $summary->belumDicentang }} baris belum dicentang
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody>
                        <tr>
                            <td class="px-4 py-1.5">Saldo per buku besar</td>
                            <td class="px-4 py-1.5 text-right font-mono whitespace-nowrap">
                                {{ Money::format($summary->saldoBuku) }}
                            </td>
                        </tr>
                        <tr>
                            <td class="px-4 py-1.5">
                                Setoran dalam perjalanan
                                <span class="text-xs opacity-70">— sudah dicatat, belum masuk bank</span>
                            </td>
                            <td class="px-4 py-1.5 text-right font-mono whitespace-nowrap">
                                −{{ Money::format($summary->setoranBeredar) }}
                            </td>
                        </tr>
                        <tr>
                            <td class="px-4 py-1.5">
                                Cek/giro beredar
                                <span class="text-xs opacity-70">— sudah dicatat keluar, belum dibayar bank</span>
                            </td>
                            <td class="px-4 py-1.5 text-right font-mono whitespace-nowrap">
                                +{{ Money::format($summary->penarikanBeredar) }}
                            </td>
                        </tr>
                        <tr class="border-t border-black/10 dark:border-white/10">
                            <td class="px-4 py-1.5 font-medium">Seharusnya di rekening koran</td>
                            <td class="px-4 py-1.5 text-right font-mono font-medium whitespace-nowrap">
                                {{ Money::format($summary->saldoDiharapkan) }}
                            </td>
                        </tr>
                        <tr>
                            <td class="px-4 py-1.5">Tertulis di rekening koran</td>
                            <td class="px-4 py-1.5 text-right font-mono whitespace-nowrap">
                                {{ Money::format($summary->saldoRekening) }}
                            </td>
                        </tr>
                        <tr class="border-t-2 border-black/20 dark:border-white/20">
                            <td class="px-4 py-2.5 text-base font-semibold">Selisih</td>
                            <td class="px-4 py-2.5 text-right font-mono text-base font-semibold whitespace-nowrap">
                                {{ Money::format($summary->selisih) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            @if ($summary->hint())
                <p class="border-t border-black/5 px-4 py-3 text-sm dark:border-white/10">
                    {{ $summary->hint() }}
                </p>
            @endif
        </div>

        {{-- What the statement had and the books did not. Above the ticking
             list because it is what closes a difference, and somebody staring
             at a difference should see the way out before the long table. --}}
        @if ($rec->items->isNotEmpty())
            <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                <h2 class="border-b border-gray-200 px-4 py-3 text-sm font-semibold
                           dark:border-white/10">
                    Item dari rekening koran
                </h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <tbody>
                            @foreach ($rec->items as $item)
                                <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        {{ $item->tanggal->format('d/m/Y') }}
                                    </td>
                                    <td class="px-4 py-2">{{ $item->keterangan }}</td>
                                    <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $item->account?->kode }} {{ $item->account?->nama }}
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono whitespace-nowrap">
                                        {{ $item->signedAmount() > 0 ? '+' : '−' }}{{ Money::format($item->amount_rupiah) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- The statement itself, once its file has been imported. Each row is
             the bank's word for a movement; the buttons decide what the books
             say it was. Above the ticking list because once the mutasi is in,
             this is the working surface and the long table is its result. --}}
        @php
            $mutasiImports = $this->mutasiImports();
            $mutasi = $this->mutasi();
        @endphp

        @if ($mutasiImports->isNotEmpty())
            <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                <div class="flex flex-wrap items-baseline justify-between gap-2 border-b
                            border-gray-200 px-4 py-3 dark:border-white/10">
                    <h2 class="text-sm font-semibold">Mutasi dari rekening koran</h2>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        @foreach ($mutasiImports as $import)
                            {{ $import->original_name }}
                            ({{ $import->status === 'gagal' ? 'gagal: '.$import->catatan : $import->jumlah_baris.' baris' }})@if (! $loop->last), @endif
                        @endforeach
                    </span>
                </div>

                @if ($mutasi->isEmpty())
                    <p class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                        Berkas tidak menghasilkan baris mutasi.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-xs uppercase tracking-wide
                                           text-gray-500 dark:border-white/10 dark:text-gray-400">
                                    <th class="px-3 py-2 text-left">Tanggal</th>
                                    <th class="px-3 py-2 text-left">Uraian</th>
                                    <th class="px-3 py-2 text-right">Masuk</th>
                                    <th class="px-3 py-2 text-right">Keluar</th>
                                    <th class="px-3 py-2 text-left">Status</th>
                                    <th class="px-3 py-2 text-left">Cocokan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($mutasi as $row)
                                    @php
                                        $baris = $row['line'];
                                        $calon = $row['candidates'];
                                    @endphp
                                    <tr @class([
                                        'border-b border-gray-100 last:border-0 dark:border-white/5',
                                        'bg-success-50/40 dark:bg-success-500/5' => $baris->status === 'tercocok',
                                        'opacity-60' => $baris->status === 'diabaikan',
                                    ])>
                                        <td class="px-3 py-2 whitespace-nowrap align-top">
                                            {{ $baris->tanggal?->format('d/m/Y') ?? '—' }}
                                        </td>
                                        <td class="px-3 py-2 align-top">
                                            {{ $baris->uraian }}
                                            @if ($baris->keterangan)
                                                <span class="block text-xs text-warning-700 dark:text-warning-400">
                                                    {{ $baris->keterangan }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono whitespace-nowrap align-top">
                                            {{ $baris->arah === 'masuk' && $baris->amount_rupiah ? Money::format((int) $baris->amount_rupiah) : '' }}
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono whitespace-nowrap align-top">
                                            {{ $baris->arah === 'keluar' && $baris->amount_rupiah ? Money::format((int) $baris->amount_rupiah) : '' }}
                                        </td>
                                        <td class="px-3 py-2 align-top">
                                            <span @class([
                                                'inline-flex rounded-md px-2 py-0.5 text-xs font-medium',
                                                'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300' => $baris->status === 'belum',
                                                'bg-success-100 text-success-800 dark:bg-success-500/20 dark:text-success-300' => $baris->status === 'tercocok',
                                                'bg-warning-100 text-warning-800 dark:bg-warning-500/20 dark:text-warning-300' => $baris->status === 'diabaikan',
                                                'bg-danger-100 text-danger-800 dark:bg-danger-500/20 dark:text-danger-300' => $baris->status === 'error',
                                            ])>
                                                {{ $baris->status }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 align-top">
                                            @if ($baris->status === 'tercocok')
                                                <span class="text-xs text-gray-600 dark:text-gray-300">
                                                    {{ $baris->journalLine?->entry?->keterangan ?? '—' }}
                                                    @if ($baris->payment_entry_id)
                                                        <em class="text-gray-400">(pembayaran dari mutasi)</em>
                                                    @endif
                                                </span>
                                                <button
                                                    type="button"
                                                    class="ms-2 text-xs text-danger-600 underline dark:text-danger-400"
                                                    wire:click="lepas({{ $baris->id }})"
                                                    wire:key="lepas-{{ $baris->id }}"
                                                >lepas</button>
                                            @elseif ($baris->status === 'belum')
                                                @if ($calon->isNotEmpty())
                                                    <div class="space-y-1">
                                                        @foreach ($calon as $kandidat)
                                                            <button
                                                                type="button"
                                                                class="block text-left text-xs text-primary-700 underline dark:text-primary-400"
                                                                wire:click="cocokkan({{ $baris->id }}, {{ $kandidat->id }})"
                                                                wire:key="cocok-{{ $baris->id }}-{{ $kandidat->id }}"
                                                            >
                                                                {{ $kandidat->entry?->tanggal?->format('d/m') }} ·
                                                                {{ \Illuminate\Support\Str::limit($kandidat->entry?->keterangan ?? '', 60) }}
                                                            </button>
                                                        @endforeach
                                                        @if ($calon->count() > 1)
                                                            <span class="block text-xs text-warning-700 dark:text-warning-400">
                                                                {{ $calon->count() }} kandidat — pilih yang benar
                                                            </span>
                                                        @endif
                                                    </div>
                                                @else
                                                    <span class="text-xs text-gray-400">tidak ada di buku</span>
                                                @endif

                                                <div class="mt-1 flex gap-2">
                                                    @if ($baris->arah === 'masuk')
                                                        {{ ($this->catatPembayaranAction)(['line' => $baris->id]) }}
                                                    @endif
                                                    {{ ($this->abaikanAction)(['line' => $baris->id]) }}
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif

        {{-- The ticking list, in the order a statement is printed in. --}}
        <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <h2 class="border-b border-gray-200 px-4 py-3 text-sm font-semibold dark:border-white/10">
                Baris buku besar sampai {{ $rec->tanggal_rekening->format('d/m/Y') }}
            </h2>

            @php $lines = $this->lines(); @endphp

            @if ($lines->isEmpty())
                <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    Tidak ada mutasi bank sampai tanggal ini.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-xs uppercase tracking-wide
                                       text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="px-3 py-2 text-left">Ada di rekening koran</th>
                                <th class="px-3 py-2 text-left">Tanggal</th>
                                <th class="px-3 py-2 text-left">Keterangan</th>
                                <th class="px-3 py-2 text-right">Masuk</th>
                                <th class="px-3 py-2 text-right">Keluar</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lines as $row)
                                @php $line = $row['line']; @endphp
                                <tr @class([
                                    'border-b border-gray-100 last:border-0 dark:border-white/5',
                                    'bg-success-50/40 dark:bg-success-500/5' => $row['ticked'],
                                ])>
                                    <td class="px-3 py-2">
                                        <input
                                            type="checkbox"
                                            class="rounded border-gray-300 text-primary-600
                                                   focus:ring-primary-500 dark:border-white/20 dark:bg-white/5"
                                            @checked($row['ticked'])
                                            wire:click="toggle({{ $line->id }})"
                                            wire:key="tick-{{ $line->id }}"
                                        >
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        {{ $line->entry?->tanggal?->format('d/m/Y') }}
                                    </td>
                                    <td class="px-3 py-2">
                                        {{ $row['keterangan'] }}
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                                            {{ $line->entry?->nomor }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                                        {{ $line->debit_rupiah > 0 ? Money::format((int) $line->debit_rupiah) : '' }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                                        {{ $line->kredit_rupiah > 0 ? Money::format((int) $line->kredit_rupiah) : '' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    {{-- What has already been signed off. Short, because the useful question
         is "when was it last done", not "show me every one". --}}
    @php $history = $this->history(); @endphp

    @if ($history->isNotEmpty())
        <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <h2 class="border-b border-gray-200 px-4 py-3 text-sm font-semibold dark:border-white/10">
                Sudah selesai
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs uppercase tracking-wide
                                   text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="px-3 py-2 text-left">Nomor</th>
                            <th class="px-3 py-2 text-left">Rekening koran</th>
                            <th class="px-3 py-2 text-right">Saldo buku</th>
                            <th class="px-3 py-2 text-right">Setoran beredar</th>
                            <th class="px-3 py-2 text-right">Cek beredar</th>
                            <th class="px-3 py-2 text-right">Saldo rekening</th>
                            <th class="px-3 py-2 text-left">Oleh</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $done)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                <td class="px-3 py-2 whitespace-nowrap">{{ $done->nomor }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ $done->tanggal_rekening->format('d/m/Y') }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                                    {{ Money::format((int) $done->saldo_buku_rupiah) }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                                    {{ Money::format((int) $done->setoran_beredar_rupiah) }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                                    {{ Money::format((int) $done->penarikan_beredar_rupiah) }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                                    {{ Money::format((int) $done->saldo_rekening_rupiah) }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ $done->finalisedBy?->name ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

</x-filament-panels::page>
