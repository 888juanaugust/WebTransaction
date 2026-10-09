{{ __('The nightly ledger check of :company found :count difference(s) between the books and their caches:', ['company' => $company, 'count' => count($findings)]) }}

@foreach (array_slice($findings, 0, 50) as $finding)
- {{ $finding->line() }}
@endforeach
@if (count($findings) > 50)
- {{ __('and :count more', ['count' => count($findings) - 50]) }}
@endif

{{ __('Nothing was changed. Run php artisan central:integrity on the server for the full list, and find the document that caused each one before touching any number.') }}
{{ __('This mail repeats every night while a difference stands.') }}
