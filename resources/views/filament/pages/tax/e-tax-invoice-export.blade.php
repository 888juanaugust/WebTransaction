<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section heading="Reference codes" description="The tax office's own codes, as written into the export file." collapsible collapsed>
        <dl class="grid grid-cols-2 md:grid-cols-4 gap-x-6 gap-y-3 text-sm">
            @foreach ($this->referenceCodes() as $label => $value)
                <div>
                    <dt class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                    <dd class="font-mono tabular-nums">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
