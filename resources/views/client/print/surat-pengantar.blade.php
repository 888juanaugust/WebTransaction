@php
    use App\Domain\Shared\Format;
    $packages = is_array($document->packages) ? $document->packages : (array) json_decode((string) $document->packages, true);
    $kinds = ['koli' => __('Boxes'), 'kresek' => __('Bags'), 'ikat' => __('Bundles'), 'palet' => __('Pallets')];
    $goods = $document->goods_description ? [$document->goods_description] : $document->lines()->with('item')->orderBy('sort')->get()->map(fn ($l) => Format::quantity((string) $l->quantity).' '.($l->unit?->name ?? '').' '.($l->item?->name ?? ''))->all();
    $goods = array_pad(array_slice($goods, 0, 4), 4, '');
    $address = $document->to_address ?: ($document->customer ? \App\Client\Domain\Customers\ShipTo::of($document->customer) : '');
    $logo = (string) config('client.theme.logo', 'images/logo.svg');
    $copies = max(1, (int) ($layout['copies'] ?? 1));
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
<div class="sheet copy pengantar">
    <div class="left">
        @unless ($pdf ?? false)<img src="{{ asset($logo) }}" alt="" class="mark">@endunless
        <p class="brand">{{ mb_strtoupper($company['name']) }}</p>
        <p class="tagline">{{ __('Automotive part distributor') }}</p>
        @if ($company['phone'])<div class="field"><span>{{ __('Tel.') }} :</span><span>{{ $company['phone'] }}</span></div>@endif
        <div class="field"><span>{{ __('No.') }}</span><span class="value">{{ $document->number }}</span></div>
        <p class="intro"><strong>{{ $title }}</strong></p>
        <table class="pack">
            <thead><tr><th>{{ __('Number of packages') }}</th></tr></thead>
            <tbody><tr><td>
                @foreach ($kinds as $key => $label)
                    <div class="count"><span class="cell">{{ isset($packages[$key]) && (int) $packages[$key] > 0 ? (int) $packages[$key] : '' }}</span><span>{{ $label }}</span></div>
                @endforeach
            </td></tr></tbody>
        </table>
    </div>
    <div class="right">
        <div class="field"><span>{{ __('fields.trans_date') }} :</span><span class="value">{{ Format::date($document->trans_date) }}</span></div>
        <div class="field"><span>{{ __('Mr / Shop') }} :</span><span class="value">{{ $document->customer?->name }}</span></div>
        <div class="field"><span>{{ __('Address') }} :</span><span class="value">{{ $address }}</span></div>
        <p class="intro">{{ __('Herewith by vehicle') }} <u>&nbsp;{{ $document->vehicle ?: '…………' }}&nbsp;</u> {{ __('No.') }} <u>&nbsp;{{ $document->plate_number ?: '…………' }}&nbsp;</u> {{ __('we send the goods listed below') }}</p>
        <table class="pack">
            <thead><tr><th>{{ __('Item name') }}</th></tr></thead>
            <tbody><tr><td class="goods">
                @foreach ($goods as $row)<div>{{ $row }}</div>@endforeach
            </td></tr></tbody>
        </table>
        <div class="signs">
            <div>{{ __('Receiver') }},<div class="line">&nbsp;</div></div>
            <div>{{ __('Yours faithfully') }},<div class="line">&nbsp;</div></div>
        </div>
    </div>
</div>
@endfor
</body>
</html>
