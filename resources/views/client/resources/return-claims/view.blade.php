@php
    use App\Client\Domain\Claims\ClaimStatus;
    use App\Domain\Shared\Format;
    $record = $this->record;
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
            <div><dt class="text-gray-500">{{ __('Status') }}</dt><dd><x-filament::badge :color="ClaimStatus::color($record->status)">{{ ClaimStatus::label($record->status) }}</x-filament::badge></dd></div>
            <div><dt class="text-gray-500">{{ __('fields.customer') }}</dt><dd class="font-medium">{{ $record->customer?->name }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Invoice') }}</dt><dd class="font-mono">{{ $record->invoice?->number }} · {{ Format::date($record->invoice?->trans_date) }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Back to warehouse') }}</dt><dd>{{ $record->warehouse?->name }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Filed by') }}</dt><dd>{{ $record->filedBy?->name }} · {{ Format::dateTime($record->created_at) }}</dd></div>
            <div class="sm:col-span-3"><dt class="text-gray-500">{{ __('Reason') }}</dt><dd>{{ $record->reason }}</dd></div>
            @if ($record->decided_at)
                <div><dt class="text-gray-500">{{ __('Decided by') }}</dt><dd>{{ $record->decidedBy?->name }} · {{ Format::dateTime($record->decided_at) }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Sales return') }}</dt><dd class="font-mono">{{ $record->salesReturn?->number ?? '—' }}</dd></div>
                @if ($record->decision_note)
                    <div class="sm:col-span-2"><dt class="text-gray-500">{{ __('Note') }}</dt><dd>{{ $record->decision_note }}</dd></div>
                @endif
            @endif
        </dl>
    </x-filament::section>
    <x-filament::section :heading="__('What comes back')">
        <table class="w-full text-sm">
            <thead><tr class="text-left"><th class="py-1 pe-3">{{ __('Item') }}</th><th class="py-1 pe-3 text-end">{{ __('fields.quantity') }}</th><th class="py-1 pe-3">{{ __('Unit') }}</th><th class="py-1">{{ __('Condition') }}</th></tr></thead>
            <tbody>
                @foreach ($record->lines()->with(['item', 'unit'])->get() as $line)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="py-1 pe-3">{{ $line->item?->number }} — {{ $line->item?->name }}</td>
                        <td class="py-1 pe-3 text-end ae-money">{{ Format::quantity((string) $line->quantity) }}</td>
                        <td class="py-1 pe-3">{{ $line->unit?->name }}</td>
                        <td class="py-1">{{ \App\Client\Domain\Stock\DamagedGoods::conditionLabels()[$line->condition] ?? $line->condition }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
    @if ($record->status === ClaimStatus::FILED && ! $this->mayDecide())
        <p class="text-sm text-gray-500">{{ __('Inventory verifies this claim; whoever filed it never does.') }}</p>
    @endif
</x-filament-panels::page>
