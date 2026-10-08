@php use App\Domain\Shared\Format; @endphp
<div class="ae-target-progress overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left">
                <th class="py-2 pe-3">{{ __('For') }}</th>
                <th class="py-2 pe-3 text-end">{{ __('Target qty') }}</th>
                <th class="py-2 pe-3 text-end">{{ __('Sold qty') }}</th>
                <th class="py-2 pe-3 text-end">{{ __('Target value') }}</th>
                <th class="py-2 pe-3 text-end">{{ __('Sold value') }}</th>
                <th class="py-2 text-end">{{ __('Reached') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="border-t border-gray-200">
                    <td class="py-2 pe-3">{{ $row['label'] }}</td>
                    <td class="py-2 pe-3 text-end ae-money">{{ Format::quantity($row['target_quantity']) }}</td>
                    <td class="py-2 pe-3 text-end ae-money">{{ Format::quantity($row['quantity']) }}</td>
                    <td class="py-2 pe-3 text-end ae-money">{{ Format::number($row['target_value']) }}</td>
                    <td class="py-2 pe-3 text-end ae-money">{{ Format::number($row['value']) }}</td>
                    <td class="py-2 text-end ae-money">{{ $row['percent'] === null ? '—' : $row['percent'].' %' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-2">{{ __('No target lines yet.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
