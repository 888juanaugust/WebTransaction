@php
    $start = $this->yearStart();
    $checks = $this->checks();
    $open = count(array_filter($checks, fn ($c) => ! $c->passed));
@endphp
<x-filament-panels::page>
    <div @class([
        'rounded-xl border p-4 text-sm',
        'border-success-300 bg-success-50 text-success-800 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-300' => $open === 0,
        'border-warning-300 bg-warning-50 text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300' => $open > 0,
    ])>
        <strong>{{ __('Fiscal year :year', ['year' => $this->yearLabel($start)]) }}</strong>
        @if ($open === 0)
            · {{ __('Everything is in place; the Owner may close the year.') }}
        @else
            · {{ __(':count item(s) stand in the way.', ['count' => $open]) }}
        @endif
    </div>

    <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <ul class="divide-y divide-black/5 dark:divide-white/10">
            @foreach ($checks as $check)
                <li class="flex gap-3 px-4 py-3">
                    @include('client.pages.partials.readiness-icon', ['passed' => $check->passed])
                    <div class="min-w-0 flex-1">
                        <p @class(['text-sm font-medium', 'text-gray-900 dark:text-gray-100' => $check->passed, 'text-danger-700 dark:text-danger-400' => ! $check->passed])>{{ $check->title }}</p>
                        @if ($check->finding)
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $check->finding }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
        <div class="border-t border-black/5 px-4 py-3 dark:border-white/10">
            {{ $this->closeAction }}
        </div>
    </div>

    @php($closed = $this->closedYears())
    @if ($closed->isNotEmpty())
        <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <div class="border-b border-black/5 px-4 py-3 dark:border-white/10">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Closed years') }}</h2>
            </div>
            <ul class="divide-y divide-black/5 dark:divide-white/10">
                @foreach ($closed as $year)
                    <li class="flex items-center gap-3 px-4 py-3 text-sm">
                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $this->yearLabel($year->fiscal_year_start->toImmutable()) }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('closed :date by :name', ['date' => \App\Domain\Shared\Format::date($year->closed_at), 'name' => $year->closedBy?->name ?? '—']) }}@if ($year->notes) · {{ $year->notes }}@endif</span>
                        <span class="ms-auto">{{ ($this->reopenAction)(['start' => $year->fiscal_year_start->toDateString()]) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
    <x-filament-actions::modals />
</x-filament-panels::page>
