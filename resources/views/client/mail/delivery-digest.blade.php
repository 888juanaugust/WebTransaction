{{ __('Orders accepted :days days ago or more, and whether their goods went out:', ['days' => $days]) }}

@foreach ($lines as $line)
- {{ $line['number'] }} · {{ $line['customer'] }} · {{ __(':n days', ['n' => $line['days']]) }} · {{ $line['state'] }} ({{ $line['delivered'] }})@if ($line['deliveries'] !== '') · {{ $line['deliveries'] }}@endif @if ($line['warehouses'] !== '') · {{ __('held in :warehouses', ['warehouses' => $line['warehouses']]) }}@endif

@endforeach
{{ __('Regards,') }}
{{ $company }}
