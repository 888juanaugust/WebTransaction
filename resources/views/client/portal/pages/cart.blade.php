@php
    use App\Domain\Shared\Format;
    $estimate = $this->estimate();
@endphp
<x-filament-panels::page>
    @if ($estimate['frozen'])
        <div class="ae-aging-banner ae-aging-banner--frozen">{{ __('New orders are frozen until the overdue invoice is settled. Contact your sales for help.') }}</div>
    @endif

    {{ $this->table }}

    @if ($estimate['lines'] !== [])
        <x-filament::section>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                <div><dt class="text-gray-500">{{ __('Subtotal') }}</dt><dd class="ae-money">{{ Format::money($estimate['subtotal']) }}</dd></div>
                <div><dt class="text-gray-500">{{ __('VAT') }}</dt><dd class="ae-money">{{ Format::money($estimate['tax_total']) }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Total') }}</dt><dd class="ae-money text-lg font-semibold">{{ Format::money($estimate['total']) }}</dd></div>
                <div>
                    <dt class="text-gray-500">{{ __('Free credit') }}</dt>
                    <dd class="ae-money {{ $estimate['over_credit'] ? 'text-danger-600' : '' }}">{{ $estimate['free_credit'] === null ? __('no limit') : Format::money($estimate['free_credit']) }}</dd>
                </div>
            </dl>
            @if ($estimate['unpriced'] > 0)
                <p class="mt-3 text-sm text-warning-700">{{ __(':n line(s) have no price yet; ask us before ordering them.', ['n' => $estimate['unpriced']]) }}</p>
            @endif
            @if ($estimate['over_credit'])
                <p class="mt-3 text-sm text-danger-700">{{ __('This order exceeds your free credit; it goes to your marketing for a decision.') }}</p>
            @endif
            <p class="mt-3 text-xs text-gray-500">{{ __('Prices are today\'s, from your agreement; the order is priced again when placed. Stock is read from :warehouse.', ['warehouse' => $estimate['warehouse']?->name]) }}</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
