{{--
    Every transition this order made, with who made it and why.

    CLAUDE.md: "Every transition is an explicit logged event with actor and
    timestamp — never a boolean flag flipped in place." The events were being
    written from the first day; this is the first screen that reads them back.

    A null actor is not a gap. Settlement and the reservation sweep have no
    person behind them — the money is the actor — and the log says "Sistem"
    rather than pretending somebody clicked.
--}}
@php
    $order = $getRecord();
    $riwayat = $order->events()->with(['actor', 'customerActor'])
        ->orderBy('created_at')->orderBy('id')->get();
@endphp

@if ($riwayat->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada perpindahan status.</p>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-xs uppercase tracking-wide
                           text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <th class="py-2 pr-4 text-left">Waktu</th>
                    <th class="py-2 pr-4 text-left">Perpindahan</th>
                    <th class="py-2 pr-4 text-left">Oleh</th>
                    <th class="py-2 text-left">Alasan</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($riwayat as $peristiwa)
                    <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                        <td class="py-2 pr-4 whitespace-nowrap">
                            {{ $peristiwa->created_at?->format('d/m/Y H:i') ?? '—' }}
                        </td>

                        <td class="py-2 pr-4">
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ $peristiwa->from_status?->label() ?? '—' }}
                            </span>
                            <span class="text-gray-400">→</span>
                            <span class="font-medium text-gray-900 dark:text-gray-100">
                                {{ $peristiwa->to_status->label() }}
                            </span>
                        </td>

                        <td class="py-2 pr-4">{{ $peristiwa->actorLabel() }}</td>

                        <td class="py-2 text-gray-600 dark:text-gray-300">
                            {{ $peristiwa->alasan ?: '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
