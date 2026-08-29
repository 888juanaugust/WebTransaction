{{--
    The Owner's pay levers, one row per selling seat.

    Rates apply from a date forward and never rewrite the past; targets
    belong to the month picked at the top. The report that reads these lives
    under Laporan → Komisi & target.
--}}
@php
    use App\Domain\Access\Role;
    use App\Domain\Money;
@endphp

<x-filament-panels::page>

    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
        <label for="bulan" class="text-sm font-medium text-gray-700 dark:text-gray-200">
            Bulan target
        </label>
        <input id="bulan" type="month" wire:model.live="bulan"
               class="mt-1 block rounded-lg border-gray-300 text-sm shadow-sm
                      dark:border-white/10 dark:bg-gray-800 dark:text-gray-100" />
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
            Tarif berlaku dari tanggalnya ke depan dan tidak pernah mengubah bulan lampau.
            Target dipasang per sales per bulan.
        </p>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs uppercase tracking-wide
                               text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="px-4 py-2 text-left">Nama</th>
                        <th class="px-4 py-2 text-left">Peran</th>
                        <th class="px-4 py-2 text-right">Tarif saat ini</th>
                        <th class="px-4 py-2 text-right">Target bulan ini</th>
                        <th class="px-4 py-2 text-left"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->kursi() as $row)
                        @php $seat = $row['user']; @endphp
                        <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                            <td class="px-4 py-2">{{ $seat->name }}</td>
                            <td class="px-4 py-2">
                                <span class="inline-flex rounded-md bg-gray-100 px-2 py-0.5 text-xs
                                             font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">
                                    {{ $seat->role()->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right font-mono">
                                {{ number_format($row['tarif'] / 100, 2, ',', '.') }}%
                            </td>
                            <td class="px-4 py-2 text-right font-mono">
                                @if ($seat->role() === Role::Sales)
                                    {{ $row['target'] !== null ? Money::format((int) $row['target']) : '—' }}
                                @else
                                    <span class="text-gray-400">tanpa target</span>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                <div class="flex justify-end gap-2">
                                    {{ ($this->ubahTarifAction)(['user' => $seat->id]) }}
                                    @if ($seat->role() === Role::Sales)
                                        {{ ($this->aturTargetAction)(['user' => $seat->id]) }}
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                Belum ada akun Sales atau Marketing yang aktif.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</x-filament-panels::page>
