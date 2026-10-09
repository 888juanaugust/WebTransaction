{{ __('Dear :name,', ['name' => $buyer->name]) }}

{{ __(':company opened a portal account for :customer in your name. Set your password with the link below; it is valid for an hour and works once.', ['company' => $company, 'customer' => $customer]) }}

{{ $url }}

{{ __('Regards,') }}
{{ $company }}
