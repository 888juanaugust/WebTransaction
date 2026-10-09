@php
    use App\Client\Models\CollectionContact;
    use App\Domain\Shared\Format;
@endphp
<div class="space-y-3 text-sm">
    @forelse ($contacts as $contact)
        <div class="rounded-xl border border-gray-200 px-3 py-2 dark:border-white/10">
            <div class="flex items-center justify-between">
                <span class="font-medium">{{ CollectionContact::outcomeLabel($contact->outcome) }}</span>
                <span class="text-xs text-gray-500">{{ Format::dateTime($contact->contacted_at) }} · {{ CollectionContact::methodLabel($contact->method) }} · {{ $contact->user?->name }}</span>
            </div>
            @if ($contact->isPromise())
                <p class="mt-1">
                    {{ __('Will pay on :date', ['date' => Format::date($contact->promise_date)]) }}{{ $contact->promise_amount ? ' · '.Format::money($contact->promise_amount) : '' }}
                    @if ($loop->first)
                        · {{ match ($kept) { true => __('kept'), false => __('missed'), null => __('open') } }}
                    @endif
                </p>
            @endif
            @if ($contact->note)
                <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $contact->note }}</p>
            @endif
        </div>
    @empty
        <p class="text-gray-500">{{ __('Nobody has contacted the customer about this invoice yet.') }}</p>
    @endforelse
</div>
