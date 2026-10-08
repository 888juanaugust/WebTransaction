<x-filament-panels::page>
    {{ $this->form }}

    @if (($summary = $this->summary()) !== null)
        <x-filament::section>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div>
                    <div class="text-xs uppercase text-gray-500">{{ __('Book balance') }}</div>
                    <div class="text-lg font-semibold tabular-nums">{{ $summary['book_balance'] }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase text-gray-500">{{ __('Cleared balance') }}</div>
                    <div class="text-lg font-semibold tabular-nums">{{ $summary['cleared_balance'] }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase text-gray-500">{{ __('Uncleared') }}</div>
                    <div class="text-lg font-semibold tabular-nums">{{ $summary['uncleared'] }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase text-gray-500">{{ __('Statement balance') }}</div>
                    <div class="text-lg font-semibold tabular-nums">{{ $summary['statement_balance'] }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase text-gray-500">{{ __('Difference') }}</div>
                    <div @class(['text-lg font-semibold tabular-nums', 'text-success-600 dark:text-success-400' => $summary['balanced'], 'text-danger-600 dark:text-danger-400' => ! $summary['balanced']])>{{ $summary['difference'] }}</div>
                </div>
            </div>

            <div class="mt-4 text-sm text-gray-500">{{ $summary['status'] }}</div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
