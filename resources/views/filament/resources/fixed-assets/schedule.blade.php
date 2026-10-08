<div class="space-y-3 text-sm">
    <p class="text-gray-600 dark:text-gray-400">
        {{ $asset->name }} ·
        Cost <span class="tabular-nums">{{ \App\Domain\Shared\Format::number($asset->costRemaining()) }}</span> ·
        Accumulated <span class="tabular-nums">{{ \App\Domain\Shared\Format::number($asset->accumulatedDepreciation()) }}</span> ·
        Book value <span class="tabular-nums">{{ \App\Domain\Shared\Format::number($asset->bookValue()) }}</span>
    </p>

    @if ($rows->isEmpty())
        <p class="text-gray-500 dark:text-gray-400">{{ __('Nothing posted yet') }}</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-700">
                    <th class="text-left py-1">{{ __('Period') }}</th>
                    <th class="text-left py-1">{{ __('Date') }}</th>
                    <th class="text-right py-1">{{ __('Amount') }}</th>
                    <th class="text-right py-1">{{ __('Accumulated') }}</th>
                    <th class="text-right py-1">{{ __('Book value') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="py-1 tabular-nums">{{ substr($row->period, 0, 4) }}-{{ substr($row->period, 4, 2) }}</td>
                        <td class="py-1">{{ \App\Domain\Shared\Format::date($row->trans_date) }}</td>
                        <td class="py-1 text-right tabular-nums">{{ \App\Domain\Shared\Format::number($row->amount) }}</td>
                        <td class="py-1 text-right tabular-nums">{{ \App\Domain\Shared\Format::number($row->accumulated_after) }}</td>
                        <td class="py-1 text-right tabular-nums">{{ \App\Domain\Shared\Format::number($row->book_value_after) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
