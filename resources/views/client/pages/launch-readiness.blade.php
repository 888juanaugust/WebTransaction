@php
    $checks = $this->checks();
    $outstanding = $this->outstanding();
    $automatic = array_values(array_filter($checks, fn ($c) => $c->automatic));
    $attested = array_values(array_filter($checks, fn ($c) => ! $c->automatic));
@endphp
<x-filament-panels::page>
    <div @class([
        'rounded-xl border p-4 text-sm',
        'border-success-300 bg-success-50 text-success-800 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-300' => $outstanding === 0,
        'border-warning-300 bg-warning-50 text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300' => $outstanding > 0,
    ])>
        @if ($outstanding === 0)
            <strong>{{ __('Nothing outstanding.') }}</strong> {{ __('The checked items are recomputed every time this screen opens; if something changes, the count rises on its own.') }}
        @else
            <strong>{{ __(':count item(s) outstanding.', ['count' => $outstanding]) }}</strong> {{ __('A checked item cannot be ticked by hand: fix the cause and its row goes green.') }}
        @endif
    </div>

    <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <div class="border-b border-black/5 px-4 py-3 dark:border-white/10">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Checked by the system') }}</h2>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Recomputed every time this screen opens. No button: nothing here is taken on trust.') }}</p>
        </div>
        <ul class="divide-y divide-black/5 dark:divide-white/10">
            @foreach ($automatic as $check)
                <li class="flex gap-3 px-4 py-3">
                    @include('client.pages.partials.readiness-icon', ['passed' => $check->passed])
                    <div class="min-w-0 flex-1">
                        <p @class(['text-sm font-medium', 'text-gray-900 dark:text-gray-100' => $check->passed, 'text-danger-700 dark:text-danger-400' => ! $check->passed])>{{ $check->title }}</p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $check->description }}</p>
                        @if ($check->finding)
                            <p @class(['mt-1 text-xs font-medium', 'text-danger-700 dark:text-danger-400' => ! $check->passed, 'text-gray-600 dark:text-gray-300' => $check->passed])>{{ $check->finding }}</p>
                        @endif
                        @if (! $check->passed && $check->action)
                            <p class="mt-1 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $check->action }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <div class="border-b border-black/5 px-4 py-3 dark:border-white/10">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Attested by a person') }}</h2>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('The system cannot check these. What is recorded is a name, a date and the evidence named; every attestation and withdrawal is in the activity log.') }}</p>
        </div>
        <ul class="divide-y divide-black/5 dark:divide-white/10">
            @foreach ($attested as $check)
                <li class="flex gap-3 px-4 py-3">
                    @include('client.pages.partials.readiness-icon', ['passed' => $check->passed])
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $check->title }}</p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $check->description }}</p>
                        @if ($check->passed)
                            <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ __('Attested by :name on :date: :note', ['name' => $check->attestedBy, 'date' => $check->attestedAt, 'note' => $check->note]) }}</p>
                        @endif
                    </div>
                    <div class="shrink-0">
                        @if ($check->passed)
                            {{ ($this->retractAction)(['key' => $check->key]) }}
                        @else
                            {{ ($this->attestAction)(['key' => $check->key]) }}
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
