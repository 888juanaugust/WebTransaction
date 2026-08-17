{{--
    The table every report renders. Controls above it come from the page.

    Caveats sit above the figures, not under them. A note explaining that a
    margin is overstated is worthless printed below the margin somebody has
    already read and quoted.
--}}
@php
    $report = $this->getReport();
    $columns = $report->visibleColumns();
@endphp

<x-filament-panels::page>
    @if ($controls = $this->controlsView())
        @include($controls)
    @endif

    @foreach ($report->catatan as $note)
        <div @class([
            'rounded-xl border p-4 text-sm',
            'border-danger-300 bg-danger-50 text-danger-800 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-300'
                => str_starts_with($note, 'PERIKSA'),
            'border-warning-300 bg-warning-50 text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300'
                => ! str_starts_with($note, 'PERIKSA'),
        ])>
            {{ $note }}
        </div>
    @endforeach

    <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-gray-200 px-4 py-3
                    dark:border-white/10">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $report->judul }}</h2>
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $report->period->label }}</span>
        </div>

        @if ($report->isEmpty())
            <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                Tidak ada data untuk periode ini.
            </p>
        @else
            {{-- Its own scroll container: a wide report must never make the
                 whole page scroll sideways. --}}
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs uppercase tracking-wide
                                   text-gray-500 dark:border-white/10 dark:text-gray-400">
                            @foreach ($columns as $column)
                                <th @class([
                                    'whitespace-nowrap px-3 py-2',
                                    'text-right' => $column->alignsRight(),
                                    'text-left' => ! $column->alignsRight(),
                                ])>{{ $column->label }}</th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($report->rows as $row)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                @foreach ($columns as $column)
                                    <td @class([
                                        'px-3 py-2',
                                        'text-right font-mono whitespace-nowrap' => $column->alignsRight(),
                                    ])>{{ $column->format($row[$column->key] ?? null) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>

                    @if ($report->hasTotals())
                        <tfoot>
                            <tr class="border-t-2 border-gray-300 font-semibold dark:border-white/20">
                                @foreach ($columns as $i => $column)
                                    <td @class([
                                        'px-3 py-2',
                                        'text-right font-mono whitespace-nowrap' => $column->alignsRight(),
                                    ])>
                                        {{ $i === 0
                                            ? 'TOTAL'
                                            : (array_key_exists($column->key, $report->totals)
                                                ? $column->format($report->totals[$column->key])
                                                : '') }}
                                    </td>
                                @endforeach
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
