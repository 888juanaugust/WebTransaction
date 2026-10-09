@php
    use App\Client\Portal\Filament\Resources\Invoices\InvoiceResource;
    use App\Domain\Shared\Format;
    $record = $this->record;
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
            <div><dt class="text-gray-500">{{ __('Status') }}</dt><dd><x-filament::badge :color="$record->payment_status === 'paid' ? 'success' : 'warning'">{{ InvoiceResource::stateLabel($record) }}</x-filament::badge></dd></div>
            <div><dt class="text-gray-500">{{ __('fields.trans_date') }}</dt><dd>{{ Format::date($record->trans_date) }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Due') }}</dt><dd>{{ Format::date($record->due_date) }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Balance') }}</dt><dd class="ae-money text-lg font-semibold">{{ Format::money($record->balance()) }}</dd></div>
            <div><dt class="text-gray-500">{{ __('fields.total') }}</dt><dd class="ae-money">{{ Format::money((int) $record->total) }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Paid') }}</dt><dd class="ae-money">{{ Format::money((int) $record->paid_amount) }}</dd></div>
            @if ((int) $record->down_payment_total > 0)
                <div><dt class="text-gray-500">{{ __('Down payment') }}</dt><dd class="ae-money">{{ Format::money((int) $record->down_payment_total) }}</dd></div>
            @endif
            @if ($record->po_number)
                <div><dt class="text-gray-500">{{ __('Your PO') }}</dt><dd>{{ $record->po_number }}</dd></div>
            @endif
        </dl>
    </x-filament::section>

    <x-filament::section :heading="__('Lines')">
        <table class="w-full text-sm">
            <thead><tr class="text-left"><th class="py-1 pe-3">{{ __('Item') }}</th><th class="py-1 pe-3 text-end">{{ __('fields.quantity') }}</th><th class="py-1 pe-3">{{ __('fields.unit') }}</th><th class="py-1 pe-3 text-end">{{ __('Price') }}</th><th class="py-1 text-end">{{ __('fields.amount') }}</th></tr></thead>
            <tbody>
                @foreach ($record->lines()->with(['item', 'unit'])->get() as $line)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="py-1 pe-3">{{ $line->item?->number }} — {{ $line->item?->name }}</td>
                        <td class="py-1 pe-3 text-end ae-money">{{ Format::quantity((string) $line->quantity) }}</td>
                        <td class="py-1 pe-3">{{ $line->unit?->name }}</td>
                        <td class="py-1 pe-3 text-end ae-money">{{ Format::money((int) round((float) $line->unit_price)) }}</td>
                        <td class="py-1 text-end ae-money">{{ Format::money((int) $line->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t border-gray-200 dark:border-white/10"><td colspan="4" class="py-1 pe-3 text-end text-gray-500">{{ __('Subtotal') }}</td><td class="py-1 text-end ae-money">{{ Format::money((int) $record->subtotal) }}</td></tr>
                <tr><td colspan="4" class="py-1 pe-3 text-end text-gray-500">{{ __('VAT') }}</td><td class="py-1 text-end ae-money">{{ Format::money((int) $record->tax_total) }}</td></tr>
                <tr><td colspan="4" class="py-1 pe-3 text-end font-medium">{{ __('fields.total') }}</td><td class="py-1 text-end ae-money font-semibold">{{ Format::money((int) $record->total) }}</td></tr>
            </tfoot>
        </table>
    </x-filament::section>
</x-filament-panels::page>
