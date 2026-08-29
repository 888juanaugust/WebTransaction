{{--
    One report chart: horizontal bars, rendered server-side.

    No script and no chart library on purpose — the bars arrive in the same
    request as the figures, print with the report, and can be asserted on by
    a feature test. Horizontal because the labels are customer and product
    names, and forty-five-degree text under a column chart is how names stop
    being read.

    Expects: $chart (App\Domain\Reporting\ReportChart)
--}}
@php
    use App\Domain\Money;

    $max = $chart->max();
@endphp

<div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
    <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $chart->judul }}</h3>

    <div class="space-y-2">
        @foreach ($chart->labels as $i => $label)
            @php $value = $chart->values[$i] ?? 0; @endphp
            <div class="flex items-center gap-3 text-sm">
                <span class="w-40 shrink-0 truncate text-gray-600 dark:text-gray-300" title="{{ $label }}">
                    {{ $label }}
                </span>
                <div class="h-4 flex-1 overflow-hidden rounded bg-gray-100 dark:bg-white/5">
                    <div
                        class="h-full rounded bg-primary-600 dark:bg-primary-400"
                        style="width: {{ $value > 0 ? max(1, round($value / $max * 100)) : 0 }}%"
                    ></div>
                </div>
                <span class="w-32 shrink-0 text-right font-mono text-xs text-gray-700 dark:text-gray-200">
                    {{ $chart->rupiah ? Money::format($value) : number_format($value, 0, ',', '.') }}
                </span>
            </div>
        @endforeach
    </div>

    @if ($chart->catatan)
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ $chart->catatan }}</p>
    @endif
</div>
