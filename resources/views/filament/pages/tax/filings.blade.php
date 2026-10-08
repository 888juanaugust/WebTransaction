<div class="space-y-3 text-sm">
    @if ($filings->isEmpty())
        <p class="text-gray-500 dark:text-gray-400">{{ __('No exports yet') }}</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-700">
                    <th class="text-left py-1">{{ __('Date/time') }}</th>
                    <th class="text-left py-1">{{ __('Period') }}</th>
                    <th class="text-left py-1">{{ __('File name') }}</th>
                    <th class="text-right py-1">{{ __('Documents') }}</th>
                    <th class="text-right py-1">{{ __('DPP') }}</th>
                    <th class="text-right py-1">{{ __('VAT') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($filings as $filing)
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="py-1 tabular-nums">{{ \App\Domain\Shared\Format::dateTime($filing->created_at) }}</td>
                        <td class="py-1 tabular-nums">{{ sprintf('%04d-%02d', $filing->period_year, $filing->period_month) }}</td>
                        <td class="py-1">
                            <span class="font-mono">{{ $filing->file_name }}</span>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $filing->file_path }}</div>
                        </td>
                        <td class="py-1 text-right tabular-nums">{{ $filing->document_count }}</td>
                        <td class="py-1 text-right tabular-nums">{{ \App\Domain\Shared\Format::number($filing->dpp_total) }}</td>
                        <td class="py-1 text-right tabular-nums">{{ \App\Domain\Shared\Format::number($filing->tax_total) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
