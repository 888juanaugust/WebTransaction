{{ __('Dear :customer,', ['customer' => $invoice->customer?->name]) }}

{{ __('Attached are tax invoice :serial and our invoice :number dated :date.', ['serial' => $serial, 'number' => $invoice->number, 'date' => \App\Domain\Shared\Format::date($invoice->trans_date)]) }}

{{ __('Regards,') }}
{{ $company }}
