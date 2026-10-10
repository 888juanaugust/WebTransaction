@php
    use App\Domain\Shared\Format;
    $lines = $document->lines()->with('invoice')->get();
    $copies = max(1, (int) ($layout['copies'] ?? 1));
    $copyLabels = [__('Customer copy'), __('File copy')];
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $document->number }}</title>
    @include('client.print._style')
</head>
<body>
@unless ($pdf ?? false)
    <div class="toolbar"><button type="button" onclick="window.print()">{{ __('Print') }}</button></div>
@endunless
@for ($copy = 0; $copy < $copies; $copy++)
<div class="sheet copy">
    @if ($copies > 1)<div class="copy-label">{{ $copyLabels[$copy] ?? __('Copy :n', ['n' => $copy + 1]) }}</div>@endif
    <div class="head">
        <div class="company">
            <h1>{{ $company['name'] }}</h1>
            @if (($layout['show_company_address'] ?? true) && $company['address'])<p>{{ $company['address'] }}</p>@endif
            @if ($company['phone'])<p>{{ __('Phone :number', ['number' => $company['phone']]) }}</p>@endif
            <div class="kepada">
                <span class="label">{{ __('Addressed to') }} :</span> <strong>{{ $document->customer?->name }}</strong>
                @if ($document->customer?->billAddress())<div>{{ $document->customer->billAddress() }}</div>@endif
            </div>
        </div>
        <div class="box">
            <h2>{{ $title }}</h2>
            <div class="cells">
                <div><small>{{ __('fields.trans_date') }}</small><strong>{{ Format::date($document->trans_date) }}</strong></div>
                <div><small>{{ __('Number') }}</small><strong>{{ $document->number }}</strong></div>
            </div>
            <div class="note"><small>{{ __('Collect on') }}</small><strong>{{ Format::date($document->collect_date) }}</strong></div>
        </div>
    </div>

    <p style="font-size: 9.5pt; margin: 4mm 0 0">{{ __('The original invoices below were received, to be collected on the date above:') }}</p>
    <table class="lines">
        <thead>
            <tr>
                <th style="width: 40mm">{{ __('Invoice No.') }}</th>
                <th style="width: 28mm">{{ __('Invoice date') }}</th>
                <th style="width: 28mm">{{ __('Due date') }}</th>
                <th>{{ __('Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td class="mono">{{ $line->invoice?->number }}</td>
                    <td>{{ $line->invoice ? Format::date($line->invoice->trans_date) : '' }}</td>
                    <td>{{ $line->invoice?->due_date ? Format::date($line->invoice->due_date) : '' }}</td>
                    <td class="num">{{ Format::rupiah((int) ($line->invoice?->total ?? 0)) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="3" style="text-align: right; font-weight: 600">{{ __('Total') }}</td>
                <td class="num" style="font-weight: 600">{{ Format::rupiah((int) $document->total) }}</td>
            </tr>
        </tbody>
    </table>
    @if ($document->description)<p style="font-size: 9.5pt; margin: 3mm 0 0">{{ __('Notes') }}: {{ $document->description }}</p>@endif

    @if ($layout['show_signature'] ?? true)
        <div class="signs">
            <div>{{ __('Handed over by') }},<div class="line">{{ __('Date signed') }}</div></div>
            <div>{{ __('Received by') }},<div class="line">{{ __('Date signed') }}</div></div>
        </div>
    @endif
    @if ($layout['footer'] ?? null)<div class="printed">{{ $layout['footer'] }}</div>@endif
</div>
@endfor
</body>
</html>
