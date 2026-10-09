<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        @if (static::canUpdate())
            <div class="mt-6 flex justify-end">
                <x-filament::button type="submit">{{ __('Save website') }}</x-filament::button>
            </div>
        @endif
    </form>
</x-filament-panels::page>
