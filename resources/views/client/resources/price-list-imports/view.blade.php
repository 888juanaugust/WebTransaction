@php
    use App\Client\Filament\Resources\PriceListImports\PriceListImportResource;
    use App\Client\Models\PriceListImport;
    use App\Domain\Shared\Format;
    $record = $this->record;
    $diff = $record->diff;
    $buckets = $diff['buckets'] ?? [];
    $tiles = [
        ['label' => __('New SKUs'), 'value' => $buckets['sku_baru'] ?? 0, 'tone' => 'info'],
        ['label' => __('Price changed'), 'value' => $buckets['harga_berubah'] ?? 0, 'tone' => 'warning'],
        ['label' => __('Unchanged'), 'value' => $buckets['tidak_berubah'] ?? 0, 'tone' => 'gray'],
        ['label' => __('Not in file'), 'value' => $buckets['tidak_ada_di_file'] ?? 0, 'tone' => 'gray'],
        ['label' => __('Errors'), 'value' => $buckets['error'] ?? 0, 'tone' => 'danger'],
    ];
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
            <div><dt class="text-gray-500">{{ __('Status') }}</dt><dd><x-filament::badge :color="match ($record->status) { PriceListImport::PUBLISHED => 'success', PriceListImport::FAILED => 'danger', PriceListImport::PARSED => 'warning', default => 'gray' }">{{ PriceListImportResource::statusLabel($record->status) }}</x-filament::badge></dd></div>
            <div><dt class="text-gray-500">{{ __('Format') }}</dt><dd>{{ $record->isCanonical() ? __('company format') : __('supplier workbook') }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Rows') }}</dt><dd class="ae-money">{{ Format::number($record->row_count) }} · {{ __(':n blocked', ['n' => $record->blocker_count]) }} · {{ __(':n annotated', ['n' => $record->note_count]) }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Uploaded') }}</dt><dd>{{ $record->uploadedBy?->name ?? '—' }} · {{ Format::dateTime($record->created_at) }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Effective from') }}</dt><dd>{{ $record->effective_from ? Format::date($record->effective_from) : '—' }}{{ $record->is_full_replacement ? ' · '.__('full replacement') : '' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Version') }}</dt><dd>{{ $record->version_id ? '#'.$record->version_id : '—' }}{{ $record->approvedBy ? ' · '.$record->approvedBy->name : '' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-gray-500">{{ __('Checksum') }}</dt><dd class="font-mono text-xs">{{ $record->checksum ?? '—' }}</dd></div>
        </dl>
        @if ($record->parse_error)
            <p class="mt-3 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-700 dark:bg-danger-500/10 dark:text-danger-300">{{ $record->parse_error }}</p>
        @endif
    </x-filament::section>

    @if ($diff)
        <x-filament::section :heading="__('Against the list in force')">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                @foreach ($tiles as $tile)
                    <div class="rounded-xl border border-gray-200 px-3 py-2 dark:border-white/10">
                        <div class="text-xs text-gray-500">{{ $tile['label'] }}</div>
                        <div class="text-xl font-semibold ae-money">{{ Format::number((int) $tile['value']) }}</div>
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-sm text-gray-500">{{ __(':share% of comparable prices change.', ['share' => number_format(($diff['changed_share_bps'] ?? 0) / 100, 1)]) }}</p>
            @if ($diff['brake_tripped'] ?? false)
                <div class="mt-3 rounded-xl border border-danger-300 bg-danger-50 px-3 py-2 text-sm text-danger-700 dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-300">
                    <div class="font-medium">{{ __('Safety brake') }}</div>
                    <ul class="mt-1 space-y-0.5">@foreach ($diff['brake_reasons'] ?? [] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                    @if ($record->brake_acknowledgement)<p class="mt-2">{{ __('Acknowledged:') }} {{ $record->brake_acknowledgement }}</p>@endif
                </div>
            @endif
        </x-filament::section>

        @if (($diff['biggest_moves'] ?? []) !== [])
            <x-filament::section :heading="__('Biggest moves')" collapsible>
                <table class="w-full text-sm">
                    <thead><tr class="text-left"><th class="py-1 pe-3">{{ __('KODE') }}</th><th class="py-1 pe-3 text-end">{{ __('Old') }}</th><th class="py-1 pe-3 text-end">{{ __('New') }}</th><th class="py-1 text-end">{{ __('Move') }}</th></tr></thead>
                    <tbody>
                        @foreach ($diff['biggest_moves'] as $move)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="py-1 pe-3 font-mono">{{ $move['kode'] }}</td>
                                <td class="py-1 pe-3 text-end ae-money">{{ Format::number((int) $move['harga_lama']) }}</td>
                                <td class="py-1 pe-3 text-end ae-money">{{ Format::number((int) $move['harga_baru']) }}</td>
                                <td class="py-1 text-end ae-money {{ $move['delta_bps'] > 0 ? 'text-danger-600' : 'text-success-700' }}">{{ ($move['delta_bps'] >= 0 ? '+' : '').number_format($move['delta_bps'] / 100, 1) }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @endif
    @endif

    @if ($this->blockers()->isNotEmpty())
        <x-filament::section :heading="__('Blocked rows')" :description="__('These rows are never published. Fix the file and upload it again, or publish without them.')" collapsible>
            <table class="w-full text-sm">
                <thead><tr class="text-left"><th class="py-1 pe-3">{{ __('Sheet · row') }}</th><th class="py-1 pe-3">{{ __('KODE') }}</th><th class="py-1">{{ __('Issue') }}</th></tr></thead>
                <tbody>
                    @foreach ($this->blockers() as $row)
                        <tr class="border-t border-gray-100 align-top dark:border-white/5">
                            <td class="py-1 pe-3 whitespace-nowrap text-gray-500">{{ $row->sheet ?? '—' }} · {{ $row->row_number }}</td>
                            <td class="py-1 pe-3 font-mono">{{ $row->kode ?? '—' }}</td>
                            <td class="py-1">@foreach ($row->issues ?? [] as $issue)@if ($issue['severity'] === 'blocker')<div>{{ $issue['message'] }}</div>@endif @endforeach</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif

    @if ($this->notes()->isNotEmpty())
        <x-filament::section :heading="__('Annotated rows')" :description="__('Published as they are; the note travels with the item.')" collapsible collapsed>
            <table class="w-full text-sm">
                <thead><tr class="text-left"><th class="py-1 pe-3">{{ __('Sheet · row') }}</th><th class="py-1 pe-3">{{ __('KODE') }}</th><th class="py-1">{{ __('Note') }}</th></tr></thead>
                <tbody>
                    @foreach ($this->notes() as $row)
                        <tr class="border-t border-gray-100 align-top dark:border-white/5">
                            <td class="py-1 pe-3 whitespace-nowrap text-gray-500">{{ $row->sheet ?? '—' }} · {{ $row->row_number }}</td>
                            <td class="py-1 pe-3 font-mono">{{ $row->kode ?? '—' }}</td>
                            <td class="py-1">{{ $row->catatan }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
