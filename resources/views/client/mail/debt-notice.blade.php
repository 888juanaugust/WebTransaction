{{ __('Dear :customer,', ['customer' => $invoice->customer?->name]) }}

{{ __('Invoice :number dated :date is :days days old; :balance is still open.', ['number' => $invoice->number, 'date' => $date, 'days' => $days, 'balance' => $balance]) }}
@if ($frozen)
{{ __('New transactions on this account are frozen until it is settled.') }}
@elseif ($freezesOn)
{{ __('From :date, new transactions on this account are frozen until it is settled.', ['date' => $freezesOn]) }}
@endif

{{ __('Regards,') }}
{{ $company }}
