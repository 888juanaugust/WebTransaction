@php use App\Domain\Shared\Format; @endphp
<div class="overflow-x-auto">
    <p class="text-sm mb-2">{{ __('The tax books\' depreciation by the asset\'s fiscal group. Not posted.') }}</p>
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left">
                <th class="py-2 pe-3">{{ __('Fiscal year from') }}</th>
                <th class="py-2 pe-3 text-end">{{ __('Months') }}</th>
                <th class="py-2 pe-3 text-end">{{ __('Opening') }}</th>
                <th class="py-2 pe-3 text-end">{{ __('Depreciation') }}</th>
                <th class="py-2 text-end">{{ __('Closing') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($years as $year)
                <tr class="border-t border-gray-200">
                    <td class="py-2 pe-3">{{ Format::date($year['year_start']) }}</td>
                    <td class="py-2 pe-3 text-end">{{ $year['months'] }}</td>
                    <td class="py-2 pe-3 text-end ae-money">{{ Format::number($year['opening']) }}</td>
                    <td class="py-2 pe-3 text-end ae-money">{{ Format::number($year['depreciation']) }}</td>
                    <td class="py-2 text-end ae-money">{{ Format::number($year['closing']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-2">{{ __('Save the asset with a cost to see its fiscal depreciation.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
