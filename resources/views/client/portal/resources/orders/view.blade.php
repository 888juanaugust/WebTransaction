@php
    use App\Client\Portal\Filament\Resources\Orders\OrderResource;
    use App\Client\Portal\PortalDocuments;
    use App\Domain\Approval\ApprovalEngine;
    use App\Domain\Shared\Format;
    $record = $this->record;
    $approved = app(ApprovalEngine::class)->isApproved($record);
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
            <div><dt class="text-gray-500">{{ __('Status') }}</dt><dd><x-filament::badge :color="OrderResource::statusColor($record)">{{ OrderResource::statusLabel($record) }}</x-filament::badge></dd></div>
            <div><dt class="text-gray-500">{{ __('fields.trans_date') }}</dt><dd>{{ Format::date($record->trans_date) }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Your PO') }}</dt><dd>{{ $record->po_number ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('fields.total') }}</dt><dd class="ae-money text-lg font-semibold">{{ Format::money((int) $record->total) }}</dd></div>
            @if ($record->description)
                <div class="sm:col-span-4"><dt class="text-gray-500">{{ __('Note') }}</dt><dd>{{ $record->description }}</dd></div>
            @endif
            @if ($record->approval_status === 'rejected' && $record->rejection_reason)
                <div class="sm:col-span-4"><dt class="text-gray-500">{{ __('Why it was rejected') }}</dt><dd>{{ $record->rejection_reason }}</dd></div>
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

    @if ($approved)
        <x-filament::section :heading="__('Deliveries')">
            @forelse ($this->deliveries() as $delivery)
                <div class="flex items-center justify-between border-t border-gray-100 py-1 text-sm first:border-t-0 dark:border-white/5">
                    <span><span class="font-mono">{{ $delivery->number }}</span> · {{ Format::date($delivery->trans_date) }}</span>
                    @if ($url = PortalDocuments::url($delivery))
                        <a href="{{ $url }}" target="_blank" class="text-primary-600 hover:underline">{{ __('Surat jalan PDF') }}</a>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('Not delivered yet.') }}</p>
            @endforelse
        </x-filament::section>

        <x-filament::section :heading="__('Invoices')">
            @forelse ($this->invoices() as $invoice)
                <div class="flex items-center justify-between border-t border-gray-100 py-1 text-sm first:border-t-0 dark:border-white/5">
                    <span><span class="font-mono">{{ $invoice->number }}</span> · {{ Format::date($invoice->trans_date) }} · {{ Format::money((int) $invoice->total) }} · {{ __('balance :amount', ['amount' => Format::money($invoice->balance())]) }}</span>
                    @if ($url = PortalDocuments::url($invoice))
                        <a href="{{ $url }}" target="_blank" class="text-primary-600 hover:underline">{{ __('Invoice PDF') }}</a>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('Not invoiced yet.') }}</p>
            @endforelse
        </x-filament::section>
    @endif
</x-filament-panels::page>
