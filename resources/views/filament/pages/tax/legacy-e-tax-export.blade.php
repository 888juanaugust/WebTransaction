<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('The older CSV layout, kept so a filing made under it can be reproduced. New filings go through the e-Tax Invoice Export.') }}
        </p>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
