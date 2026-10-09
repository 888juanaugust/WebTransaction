{{ __('The health check of :company found this at :time:', ['company' => $company, 'time' => now()->format('d/m/Y H:i')]) }}

@foreach ($failing as $check)
- {{ strtoupper($check->status->label()) }}: {{ $check->title }}. {{ $check->finding }}
@endforeach

{{ __('Details from the server: php artisan central:health. Runbook: docs/DEPLOY.md, "When something is wrong".') }}
{{ __('This mail is sent once per incident; the next one comes after the box recovers and fails again.') }}
