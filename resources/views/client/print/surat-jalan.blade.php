@php
    use App\Domain\Shared\Format;
    $lines = $document->lines()->with(['item', 'unit'])->orderBy('sort')->get();
    $copies = max(1, (int) ($layout['copies'] ?? 1));
    $copyLabels = [__('Customer copy'), __('Expedition copy'), __('File copy')];
    $expedition = $document->shipment;
    $note = collect([$expedition?->name, $document->shipping_note])->filter()->join(' · ');
    $address = $document->to_address ?: ($document->customer ? \App\Client\Domain\Customers\ShipTo::of($document->customer) : '');
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
                <span class="label">{{ __('Deliver to') }} :</span> <strong>{{ $document->customer?->name }}</strong>
                @if ($address)<div>{{ $address }}</div>@endif
            </div>
        </div>
        <div class="box">
            <h2>{{ $title }}</h2>
            <div class="cells">
                <div><small>{{ __('fields.trans_date') }}</small><strong>{{ Format::date($document->trans_date) }}</strong></div>
                <div><small>{{ __('Number') }}</small><strong>{{ $document->number }}</strong></div>
            </div>
            <div class="note"><small>{{ __('Note') }}</small>{{ $note }}</div>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th style="width: 30mm">{{ __('Item code') }}</th>
                <th style="width: 16mm">{{ __('Qty') }}</th>
                <th style="width: 14mm">{{ __('Unit') }}</th>
                <th>{{ __('Item name') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td class="mono">{{ $line->item?->number }}</td>
                    <td class="num">{{ Format::quantity((string) $line->quantity) }}</td>
                    <td>{{ $line->unit?->name }}</td>
                    <td>{{ $line->item?->name }}@if ($line->item?->part_number) ({{ $line->item->part_number }})@endif @if ($line->memo)<br><small>{{ $line->memo }}</small>@endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($document->description)<p style="font-size: 9.5pt; margin: 3mm 0 0">{{ __('Notes') }}: {{ $document->description }}</p>@endif

    @if ($layout['show_signature'] ?? true)
        <div class="signs">
            <div>{{ __('Collected by') }} :<div class="line">{{ __('Date signed') }}</div></div>
            <div>{{ __('Checked and packed by') }} :<div class="line">{{ __('Date signed') }}</div></div>
            <div>{{ __('Warehouse head signature') }}<div class="line">{{ __('Date signed') }} &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; {{ __('Time signed') }} :</div></div>
        </div>
    @endif
    <div class="printed">{{ __('printed by') }} : {{ auth()->user()?->name ?? '—' }} · {{ Format::date(now()) }}, {{ now()->format('H:i') }}</div>
    @if ($layout['footer'] ?? null)<div class="printed">{{ $layout['footer'] }}</div>@endif
</div>
@endfor
</body>
</html>
