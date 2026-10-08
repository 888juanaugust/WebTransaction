@php use App\Domain\Shared\Format; @endphp
<table class="w-full text-sm">
    <thead>
        <tr class="text-left">
            <th class="py-2 pe-3">{{ __('Number') }}</th>
            <th class="py-2 pe-3">{{ __('Period') }}</th>
            <th class="py-2 pe-3 text-end">{{ __('VAT out') }}</th>
            <th class="py-2 pe-3 text-end">{{ __('VAT in') }}</th>
            <th class="py-2 text-end">{{ __('Payable') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($returns as $return)
            <tr class="border-t border-gray-200">
                <td class="py-2 pe-3 font-mono">{{ $return->number }}</td>
                <td class="py-2 pe-3">{{ Format::date($return->from_date) }} – {{ Format::date($return->until_date) }}</td>
                <td class="py-2 pe-3 text-end ae-money">{{ Format::number($return->vat_out) }}</td>
                <td class="py-2 pe-3 text-end ae-money">{{ Format::number($return->vat_in) }}</td>
                <td class="py-2 text-end ae-money">{{ Format::number($return->payable) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-2">{{ __('No VAT return saved yet.') }}</td></tr>
        @endforelse
    </tbody>
</table>
