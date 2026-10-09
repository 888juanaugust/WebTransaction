@php
    use App\Domain\Shared\Format;
@endphp
@if ($strip['state'] === 'frozen')
    <div class="ae-aging-banner ae-aging-banner--frozen">
        {{ __('Your account is frozen for new orders: invoice :number is :days days old. Settle it and ordering reopens at once.', ['number' => $strip['oldest']?->number, 'days' => $strip['oldest_days']]) }}
    </div>
@elseif ($strip['state'] === 'notice')
    <div class="ae-aging-banner">
        {{ __('Invoice :number is :days days old. From :date new orders are frozen until it is settled.', ['number' => $strip['oldest']?->number, 'days' => $strip['oldest_days'], 'date' => $strip['freezes_on'] ? Format::date($strip['freezes_on']) : '—']) }}
    </div>
@endif
<div class="ae-credit-strip">
    <div class="ae-credit-strip__item">
        <div class="ae-credit-strip__label">{{ __('Free credit') }}</div>
        <div class="ae-credit-strip__value {{ $strip['free_credit'] !== null && $strip['free_credit'] <= 0 ? 'ae-credit-strip__value--danger' : '' }}">{{ $strip['free_credit'] === null ? __('no limit') : Format::money($strip['free_credit']) }}</div>
    </div>
    <div class="ae-credit-strip__item">
        <div class="ae-credit-strip__label">{{ __('You owe') }}</div>
        <div class="ae-credit-strip__value">{{ Format::money($strip['exposure']) }}</div>
    </div>
    <div class="ae-credit-strip__item">
        <div class="ae-credit-strip__label">{{ __('On order') }}</div>
        <div class="ae-credit-strip__value">{{ Format::money($strip['open_orders']) }}</div>
    </div>
</div>
