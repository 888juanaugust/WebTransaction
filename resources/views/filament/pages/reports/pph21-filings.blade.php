<div class="space-y-3 text-sm">
    @if ($filings->isEmpty())
        <p class="text-gray-500">{{ __('No exports yet') }}</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200">
                    <th class="text-left py-1">{{ __('Number') }}</th>
                    <th class="text-left py-1">{{ __('Period') }}</th>
                    <th class="text-left py-1">{{ __('Date/time') }}</th>
                    <th class="text-right py-1">{{ __('Employees') }}</th>
                    <th class="text-right py-1">{{ __('Gross') }}</th>
                    <th class="text-right py-1">{{ __('Income tax') }}</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($filings as $filing)
                    <tr class="border-b border-gray-100">
                        <td class="py-1 font-mono">{{ $filing->number }}</td>
                        <td class="py-1 tabular-nums">{{ $filing->kind === \App\Domain\Tax\Pph21Filings::ANNUAL ? $filing->period_year : sprintf('%04d-%02d', $filing->period_year, $filing->period_month) }}</td>
                        <td class="py-1 tabular-nums">{{ \App\Domain\Shared\Format::dateTime($filing->created_at) }}</td>
                        <td class="py-1 text-right tabular-nums">{{ $filing->document_count }}</td>
                        <td class="py-1 text-right tabular-nums">{{ \App\Domain\Shared\Format::number($filing->dpp_total) }}</td>
                        <td class="py-1 text-right tabular-nums">{{ \App\Domain\Shared\Format::number($filing->tax_total) }}</td>
                        <td class="py-1 text-right"><x-filament::link tag="button" wire:click="downloadFiling({{ $filing->id }})">{{ __('Download') }}</x-filament::link></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
