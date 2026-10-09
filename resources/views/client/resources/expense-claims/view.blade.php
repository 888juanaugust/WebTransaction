@php
    use App\Client\Domain\Claims\ClaimStatus;
    use App\Domain\Shared\Format;
    $record = $this->record;
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
            <div><dt class="text-gray-500">{{ __('Status') }}</dt><dd><x-filament::badge :color="ClaimStatus::color($record->status)">{{ ClaimStatus::label($record->status) }}</x-filament::badge></dd></div>
            <div><dt class="text-gray-500">{{ __('Sales') }}</dt><dd class="font-medium">{{ $record->salesUser?->name }}</dd></div>
            <div><dt class="text-gray-500">{{ __('fields.date') }}</dt><dd>{{ Format::date($record->trans_date) }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Amount') }}</dt><dd class="ae-money text-lg font-semibold">{{ Format::money((int) $record->amount) }}</dd></div>
            <div><dt class="text-gray-500">{{ __('For a customer') }}</dt><dd>{{ $record->customer?->name ?? __('road costs') }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Filed') }}</dt><dd>{{ Format::dateTime($record->created_at) }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-gray-500">{{ __('Spent on') }}</dt><dd>{{ $record->description }}</dd></div>
            @if ($record->decided_at)
                <div><dt class="text-gray-500">{{ __('Decided by') }}</dt><dd>{{ $record->decidedBy?->name }} · {{ Format::dateTime($record->decided_at) }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Payment') }}</dt><dd class="font-mono">{{ $record->payment?->number ?? '—' }}</dd></div>
                @if ($record->decision_note)
                    <div class="sm:col-span-2"><dt class="text-gray-500">{{ __('Note') }}</dt><dd>{{ $record->decision_note }}</dd></div>
                @endif
            @endif
        </dl>
    </x-filament::section>
    @if ($record->status === ClaimStatus::FILED && ! $this->mayDecide())
        <p class="text-sm text-gray-500">{{ __('Finance verifies this claim; whoever filed it never does.') }}</p>
    @endif
</x-filament-panels::page>
