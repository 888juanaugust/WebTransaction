{{--
    Neraca saldo, with the control accounts above it.

    The reconciliation panel is first on purpose. The trial balance below it
    cannot fail — Ledger refuses an unbalanced entry — so it is reassurance
    rather than information. The panel is the part that can actually tell you
    something is wrong.
--}}
@php
    use App\Domain\Money;

    $tb = $this->getTrialBalance();
    $rows = $this->getRows();
    $checks = $this->getChecks();
    $drift = collect($checks)->reject(fn ($c) => $c->agrees());
@endphp

<x-filament-panels::page>
    <section @class([
        'rounded-xl border p-4',
        'border-danger-300 bg-danger-50 dark:border-danger-500/30 dark:bg-danger-500/10' => $drift->isNotEmpty(),
        'border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900' => $drift->isEmpty(),
    ])>
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-sm font-semibold">Akun kontrol terhadap buku pembantu</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400">Selalu per hari ini, bukan per tanggal di bawah.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide
                               text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Akun</th>
                        <th class="py-2 px-3 text-right">Buku besar</th>
                        <th class="py-2 px-3 text-right">Buku pembantu</th>
                        <th class="py-2 px-3 text-right">Selisih</th>
                        <th class="py-2 pl-3">Sumber</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($checks as $check)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                            <td class="py-2 pr-3">
                                <span class="font-mono text-xs text-gray-400 dark:text-gray-500">{{ $check->kode }}</span>
                                <span class="ml-2">{{ $check->nama }}</span>
                            </td>
                            {{-- nowrap: a rupiah figure broken across two lines is unreadable,
                                 and the sumber column beside it will grow again. --}}
                            <td class="py-2 px-3 text-right font-mono tabular-nums whitespace-nowrap">{{ Money::format($check->buku) }}</td>
                            <td class="py-2 px-3 text-right font-mono tabular-nums whitespace-nowrap">{{ Money::format($check->subledger) }}</td>
                            <td @class([
                                'py-2 px-3 text-right font-mono font-semibold tabular-nums whitespace-nowrap',
                                'text-danger-600 dark:text-danger-400' => ! $check->agrees(),
                                'text-gray-400 dark:text-gray-500' => $check->agrees(),
                            ])>{{ $check->agrees() ? '—' : Money::format($check->selisih()) }}</td>
                            <td class="py-2 pl-3 text-xs text-gray-500 dark:text-gray-400">{{ $check->sumber }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($drift->isNotEmpty())
            <p class="mt-3 text-sm text-danger-700 dark:text-danger-400">
                Buku besar tidak lagi cocok dengan buku pembantu. Penyebab yang paling mungkin adalah
                jurnal manual ke akun kontrol, atau aturan posting di
                <code>DocumentPoster</code> yang salah.
            </p>
        @endif
    </section>

    <div class="flex flex-wrap items-end gap-4">
        <label class="text-sm">
            <span class="mb-1 block font-medium text-gray-700 dark:text-gray-200">Per tanggal</span>
            <input type="date" wire:model.live="tanggal"
                   class="fi-input block rounded-lg border-gray-300 bg-white text-sm shadow-sm
                          dark:border-white/10 dark:bg-white/5 dark:text-white">
        </label>

        <label class="flex items-center gap-2 pb-2 text-sm">
            <input type="checkbox" wire:model.live="sembunyikanKosong"
                   class="rounded border-gray-300 dark:border-white/20 dark:bg-white/5">
            <span>Sembunyikan akun tanpa mutasi</span>
        </label>

        {{--
            The native date input renders in the browser's own locale, which is
            not always d/m/Y. Restating the resolved date in Indonesian removes
            the ambiguity without replacing the picker.
        --}}
        <p class="pb-2 text-sm text-gray-500 dark:text-gray-400">
            Saldo sampai {{ $this->asOf()->translatedFormat('j F Y') }}.
        </p>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide
                           text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <th class="py-2 pl-4 pr-3">Kode</th>
                    <th class="py-2 px-3">Nama akun</th>
                    <th class="py-2 px-3 text-right">Debit</th>
                    <th class="py-2 pr-4 pl-3 text-right">Kredit</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                        <td class="py-2 pl-4 pr-3 font-mono text-xs">{{ $row->account->kode }}</td>
                        <td class="py-2 px-3">
                            {{ $row->account->nama }}
                            @if ($row->isContrary())
                                <span class="ml-2 rounded bg-warning-100 px-1.5 py-0.5 text-xs text-warning-700
                                             dark:bg-warning-500/20 dark:text-warning-400">saldo terbalik</span>
                            @endif
                        </td>
                        <td class="py-2 px-3 text-right font-mono tabular-nums">
                            {{ $row->debitBalance() === 0 ? '—' : Money::format($row->debitBalance()) }}
                        </td>
                        <td class="py-2 pr-4 pl-3 text-right font-mono tabular-nums">
                            {{ $row->kreditBalance() === 0 ? '—' : Money::format($row->kreditBalance()) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            Belum ada jurnal sampai tanggal ini.
                        </td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-gray-300 dark:border-white/20">
                    <th colspan="2" class="py-2 pl-4 pr-3 text-left text-sm font-semibold">
                        Total mutasi
                    </th>
                    <td class="py-2 px-3 text-right font-mono text-sm font-semibold tabular-nums">
                        {{ Money::format($tb->totalDebit()) }}
                    </td>
                    <td class="py-2 pr-4 pl-3 text-right font-mono text-sm font-semibold tabular-nums">
                        {{ Money::format($tb->totalKredit()) }}
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

    @unless ($tb->isBalanced())
        <div class="rounded-lg border border-danger-300 bg-danger-50 p-4 text-sm text-danger-700
                    dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-400">
            Debit dan kredit tidak sama — selisih {{ Money::format($tb->difference()) }}.
            Ini tidak mungkin terjadi lewat aplikasi; ada yang menulis langsung ke tabel jurnal.
        </div>
    @endunless
</x-filament-panels::page>
