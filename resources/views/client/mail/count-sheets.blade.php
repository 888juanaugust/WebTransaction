@if ($what === 'approve')
{{ __('These counts are done and wait for approval:') }}
@else
{{ __('These count sheets are still to be counted:') }}
@endif

@foreach ($lines as $line)
- {{ $line['number'] }} · {{ $line['warehouse'] }} · {{ $line['date'] }} · {{ __(':n item(s)', ['n' => $line['items']]) }}
@endforeach

{{ __('Regards,') }}
{{ $company }}
