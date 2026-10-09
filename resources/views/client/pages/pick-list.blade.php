@php use App\Domain\Shared\Format; @endphp
<div class="space-y-3 text-sm">
    <p class="text-gray-500">
        {{ $order->customer?->name }} · {{ Format::date($order->trans_date) }} · {{ $warehouse?->name }}
        @if ($order->to_address) · {{ $order->to_address }} @endif
    </p>
    <table class="w-full">
        <thead><tr class="text-left"><th class="py-1 pe-3">{{ __('Item') }}</th><th class="py-1 pe-3">{{ __('Part number') }}</th><th class="py-1 text-end">{{ __('Pick') }}</th></tr></thead>
        <tbody>
            @foreach ($held as $row)
                <tr class="border-t border-gray-100 dark:border-white/5">
                    <td class="py-1 pe-3">{{ $row['line']->item?->number }} — {{ $row['line']->item?->name }}</td>
                    <td class="py-1 pe-3 font-mono text-gray-500">{{ $row['line']->item?->part_number ?? '—' }}</td>
                    <td class="py-1 text-end ae-money">{{ Format::quantity($row['quantity']) }} {{ $row['line']->item?->unit1?->name }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
