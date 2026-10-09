@php
    use App\Domain\Shared\Format;
    $order = $this->order();
@endphp
<x-filament-widgets::widget>
    <x-filament::section :heading="__('Order your last order again')" :description="$order ? __(':number of :date', ['number' => $order->number, 'date' => Format::date($order->trans_date)]) : null">
        @if ($order === null)
            <p class="text-sm text-gray-500">{{ __('No order yet. Add items from the catalogue to start.') }}</p>
        @else
            <form wire:submit="placeAgain" class="space-y-3">
                <table class="w-full text-sm">
                    <thead><tr class="text-left"><th class="py-1 pe-3">{{ __('Item') }}</th><th class="py-1 pe-3 text-end">{{ __('fields.quantity') }}</th><th class="py-1">{{ __('fields.unit') }}</th></tr></thead>
                    <tbody>
                        @foreach ($order->lines as $line)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="py-1 pe-3">{{ $line->item?->number }} — {{ $line->item?->name }}</td>
                                <td class="py-1 pe-3 text-end">
                                    <x-filament::input.wrapper class="inline-block w-28">
                                        <x-filament::input type="number" min="0" step="any" wire:model="quantities.{{ $line->id }}" />
                                    </x-filament::input.wrapper>
                                </td>
                                <td class="py-1">{{ $line->unit?->name }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="text-xs text-gray-500">{{ __('Zero drops a line. Prices are set when the order is placed; it goes to your marketing for approval.') }}</p>
                <x-filament::button type="submit" icon="heroicon-m-arrow-path">{{ __('Order again') }}</x-filament::button>
            </form>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
