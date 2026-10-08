<x-filament-panels::page>
    <div class="max-w-md">
        <x-filament::input.wrapper>
            <x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Find a report') }}" />
        </x-filament::input.wrapper>
        <p class="mt-2 text-sm text-gray-500">{{ __('Figures are computed from the journal and the stock ledger each time; nothing is stored.') }}</p>
    </div>

    @php($groups = $this->groups())

    @forelse ($groups as $group => $reports)
        <div>
            <h2 class="text-xs uppercase tracking-wide text-gray-500 mt-6 mb-2">{{ $group }}</h2>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($reports as $class)
                    <a href="{{ $class::getUrl() }}" class="block">
                        <x-filament::section>
                            <div class="font-medium">{{ $class::title() }}</div>
                            <div class="text-sm text-gray-500">{{ $class::description() }}</div>
                        </x-filament::section>
                    </a>
                @endforeach
            </div>
        </div>
    @empty
        <p class="text-sm text-gray-500">{{ __('No report matches.') }}</p>
    @endforelse
</x-filament-panels::page>
