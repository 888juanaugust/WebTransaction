@php
    use App\Domain\Shared\Format;
    // The print's colour tokens (DESIGN.md, print-safe); the accent follows a client's primary colour when it is a hex.
    $primary = config('client.theme.colors.primary');
    $tone = [
        'ink' => '#111827', 'text' => '#374151', 'muted' => '#6b7280', 'rule' => '#e5e7eb', 'paper' => '#fff',
        'accent' => is_string($primary) && preg_match('/^#[0-9a-f]{3,8}$/i', $primary) ? $primary : '#2f5bea',
    ];
    $s = $layout;
    $shape = $meta['shape'];
    $party = $meta['party'] ? $document->{$meta['party']} : null;
    $landscape = ($s['orientation'] ?? 'portrait') === 'landscape';
    $paper = $s['paper'] ?? 'A4';
    $lines = method_exists($document, 'lines') ? $document->lines()->get() : collect();
    // What the rows show of each line, loaded at once (lazy loading is refused).
    $lines->load(array_values(array_filter(['item', 'unit', 'warehouse', 'account'], fn (string $relation) => $lines->isNotEmpty() && method_exists($lines->first(), $relation))));
    // A document in a foreign currency prints its own currency's amounts (the fc_* columns), with the rate and VAT in the base currency.
    $currencyId = $document->getAttribute('currency_id');
    $foreign = \App\Domain\Currency\Currencies::isForeign($currencyId);
    $amount = fn ($model, string $column) => $foreign && $model->getAttribute('fc_'.$column) !== null
        ? \App\Filament\Support\CurrencyFields::number((int) $model->getAttribute('fc_'.$column), $currencyId)
        : Format::number((int) $model->getAttribute($column));
    $grand = fn (string $column, ?string $less = null) => $foreign && $document->getAttribute('fc_'.$column) !== null
        ? \App\Filament\Support\CurrencyFields::format((int) $document->getAttribute('fc_'.$column) - ($less ? (int) $document->getAttribute('fc_'.$less) : 0), $currencyId)
        : Format::rupiah((int) ($document->getAttribute($column) ?? 0) - ($less ? (int) ($document->getAttribute($less) ?? 0) : 0));
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $document->number }}</title>
    <style>
        @page { size: {{ $paper === 'Continuous 9.5"' ? '241mm 279mm' : $paper }} {{ $landscape ? 'landscape' : 'portrait' }}; margin: 14mm; }
        * { box-sizing: border-box; }
        body { font-family: "Geist Variable", Geist, "Helvetica Neue", Arial, sans-serif; font-size: 11pt; color: {{ $tone['ink'] }}; margin: 0; }
        .sheet { max-width: {{ $landscape ? '277mm' : '190mm' }}; margin: 0 auto; padding: 10mm 0; }
        header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid {{ $tone['ink'] }}; padding-bottom: 8px; margin-bottom: 14px; }
        .company h1 { font-size: 16pt; margin: 0 0 2px; }
        .company p, .meta p { margin: 0; font-size: 9.5pt; color: {{ $tone['text'] }}; }
        .logo { width: 44px; height: 44px; border-radius: 10px; background: {{ $tone['accent'] }}; color: {{ $tone['paper'] }}; display: flex; align-items: center; justify-content: center; font-weight: 700; margin-right: 10px; float: left; }
        .doc-title { font-size: 18pt; font-weight: 700; letter-spacing: -0.01em; text-align: right; margin: 0; }
        .meta { text-align: right; }
        .parties { display: flex; gap: 24px; margin-bottom: 14px; }
        .parties > div { flex: 1; }
        .label { font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.06em; color: {{ $tone['muted'] }}; margin-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th { font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.04em; color: {{ $tone['muted'] }}; text-align: left; border-bottom: 1px solid {{ $tone['ink'] }}; padding: 6px 6px; }
        td { padding: 6px 6px; border-bottom: 1px solid {{ $tone['rule'] }}; vertical-align: top; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .mono { font-family: "Geist Mono Variable", ui-monospace, monospace; font-size: 9.5pt; }
        .totals { margin-left: auto; width: 60%; }
        .totals td { border: 0; padding: 3px 6px; }
        .totals tr.grand td { border-top: 2px solid {{ $tone['ink'] }}; font-weight: 700; font-size: 12pt; }
        .notes { font-size: 9.5pt; color: {{ $tone['text'] }}; white-space: pre-line; }
        .signatures { display: flex; gap: 24px; margin-top: 36px; }
        .signatures > div { flex: 1; text-align: center; font-size: 9.5pt; color: {{ $tone['text'] }}; }
        .signatures .line { border-top: 1px solid {{ $tone['ink'] }}; margin-top: 48px; padding-top: 4px; }
        footer { margin-top: 18px; font-size: 8.5pt; color: {{ $tone['muted'] }}; border-top: 1px solid {{ $tone['rule'] }}; padding-top: 6px; }
        .toolbar { position: fixed; top: 10px; right: 10px; }
        .toolbar button { font: inherit; padding: 8px 14px; border-radius: 10px; border: 0; background: {{ $tone['accent'] }}; color: {{ $tone['paper'] }}; cursor: pointer; }
        @media print { .toolbar { display: none; } .sheet { padding: 0; } }
        @if ($pdf ?? false)
        /* The PDF engine lays out blocks and tables, not flex boxes. */
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; }
        .toolbar { display: none; }
        .sheet { padding: 0; max-width: none; }
        header, .parties, .signatures { display: block; }
        header::after, .parties::after { content: ""; display: block; clear: both; }
        .company { float: left; width: 60%; }
        .meta { float: right; width: 38%; }
        .logo { display: none; }
        .parties > div { display: inline-block; width: 48%; vertical-align: top; }
        .signatures > div { display: inline-block; width: 31%; vertical-align: top; }
        @endif
    </style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">{{ __('Print') }}</button></div>
<div class="sheet">
    <header>
        <div class="company">
            @if ($s['show_logo'] ?? true)<div class="logo">{{ mb_substr($company['name'], 0, 1) }}</div>@endif
            <h1>{{ $company['name'] }}</h1>
            @if (($s['show_company_address'] ?? true) && $company['address'])<p>{{ $company['address'] }}</p>@endif
            @if ($company['phone'] || $company['fax'] || $company['email'])<p>{{ collect([$company['phone'] ? __('Phone :number', ['number' => $company['phone']]) : null, $company['fax'] ? __('Fax :number', ['number' => $company['fax']]) : null, $company['email'] ?: null])->filter()->join('  ·  ') }}</p>@endif
            @if (($s['show_tax_id'] ?? true) && $company['npwp'])<p>NPWP {{ $company['npwp'] }}</p>@endif
        </div>
        <div class="meta">
            <p class="doc-title">{{ $title }}</p>
            <p><strong class="mono">{{ $document->number }}</strong></p>
            <p>{{ Format::date($document->trans_date) }}</p>
            @if (isset($document->due_date) && $document->due_date)<p>{{ __('Due :date', ['date' => Format::date($document->due_date)]) }}</p>@endif
            @if (isset($document->po_number) && $document->po_number)<p>{{ __('PO :number', ['number' => $document->po_number]) }}</p>@endif
        </div>
    </header>

    @if ($party)
        <div class="parties">
            <div>
                <div class="label">{{ $meta['party'] === 'customer' ? __('Bill to') : __('Vendor') }}</div>
                <strong>{{ $party->name }}</strong>
                @if (method_exists($party, 'billAddress') && $party->billAddress())<div>{{ $party->billAddress() }}</div>@endif
                @if (($s['show_tax_id'] ?? true) && ($party->wp_number ?? null))<div>NPWP {{ $party->wp_number }}</div>@endif
            </div>
            @if (isset($document->to_address) && $document->to_address)
                <div><div class="label">{{ __('Ship to') }}</div><div class="notes">{{ $document->to_address }}</div></div>
            @endif
            @if (isset($document->payment_term_id) && $document->payment_term_id && method_exists($document, 'paymentTerm'))
                <div><div class="label">{{ __('Terms') }}</div>{{ $document->paymentTerm?->name }}</div>
            @endif
        </div>
    @endif

    @if ($shape === 'priced')
        <table>
            <thead><tr>
                @if ($s['show_item_code'] ?? true)<th>{{ __('Code') }}</th>@endif
                <th>{{ __('Description') }}</th>
                <th class="num">{{ __('Qty') }}</th>
                @if ($s['show_unit'] ?? true)<th>{{ __('Unit') }}</th>@endif
                <th class="num">{{ __('Price') }}</th>
                @if ($s['show_discount'] ?? true)<th class="num">{{ __('Disc') }}</th>@endif
                @if ($s['show_tax'] ?? true)<th class="num">{{ __('VAT') }}</th>@endif
                <th class="num">{{ __('Amount') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($lines as $line)
                <tr>
                    @if ($s['show_item_code'] ?? true)<td class="mono">{{ $line->item?->number }}</td>@endif
                    <td>{{ $line->item?->name }}@if ($line->memo)<br><small>{{ $line->memo }}</small>@endif</td>
                    <td class="num">{{ Format::quantity($line->quantity) }}</td>
                    @if ($s['show_unit'] ?? true)<td>{{ $line->unit?->name }}</td>@endif
                    <td class="num">{{ $foreign && $line->fc_unit_price !== null ? \App\Filament\Support\CurrencyFields::price($line->fc_unit_price, $currencyId) : Format::price($line->unit_price) }}</td>
                    @if ($s['show_discount'] ?? true)<td class="num">{{ $line->discount_amount ? $amount($line, 'discount_amount') : '' }}</td>@endif
                    @if ($s['show_tax'] ?? true)<td class="num">{{ $line->tax_amount ? $amount($line, 'tax_amount') : '' }}</td>@endif
                    <td class="num">{{ $amount($line, 'amount') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <table class="totals">
            <tr><td>{{ __('Subtotal') }}</td><td class="num">{{ $amount($document, 'subtotal') }}</td></tr>
            @if ((int) ($document->discount_amount ?? 0))<tr><td>{{ __('Discount') }}</td><td class="num">−{{ $amount($document, 'discount_amount') }}</td></tr>@endif
            @if ((int) ($document->charges_total ?? 0))<tr><td>{{ __('Other charges') }}</td><td class="num">{{ $amount($document, 'charges_total') }}</td></tr>@endif
            @if (($s['show_tax'] ?? true) && (int) ($document->tax_total ?? 0))<tr><td>{{ __('VAT') }}</td><td class="num">{{ $amount($document, 'tax_total') }}</td></tr>@endif
            @if ((int) ($document->down_payment_total ?? 0))<tr><td>{{ __('Down payment') }}</td><td class="num">−{{ $amount($document, 'down_payment_total') }}</td></tr>@endif
            <tr class="grand"><td>{{ __('Total') }}</td><td class="num">{{ $grand('total', 'down_payment_total') }}</td></tr>
        </table>
        @if ($foreign)
            <p class="notes">{{ __('Rate :rate per :code.', ['rate' => Format::quantity((string) $document->exchange_rate, 8), 'code' => \App\Domain\Currency\Currencies::code($currencyId)]) }}@if ((int) ($document->tax_total ?? 0)) {{ __('DPP :dpp and VAT :vat in :symbol, at the tax rate :rate.', ['dpp' => Format::number((int) $document->dpp_total), 'vat' => Format::number((int) $document->tax_total), 'symbol' => Format::symbol(), 'rate' => Format::quantity((string) ($document->tax_exchange_rate ?: $document->exchange_rate), 8)]) }}@endif</p>
        @endif
    @elseif ($shape === 'settlement')
        <table>
            <thead><tr><th>{{ __('Document') }}</th><th class="num">{{ __('Applied') }}</th><th class="num">{{ __('Discount') }}</th></tr></thead>
            <tbody>
            @foreach ($lines as $line)
                @php $target = $line->receivable ?? $line->payable ?? null; @endphp
                <tr><td class="mono">{{ $target?->number ?? '—' }}</td><td class="num">{{ $amount($line, 'amount') }}</td><td class="num">{{ $line->discount ? $amount($line, 'discount') : '' }}</td></tr>
            @endforeach
            </tbody>
        </table>
        <table class="totals">
            <tr><td>{{ $meta['party'] === 'customer' ? __('Received into') : __('Paid from') }}</td><td class="num">{{ $document->bankAccount?->name }}</td></tr>
            @if ($document->cheque_no ?? null)<tr><td>{{ __('Cheque / giro') }}</td><td class="num">{{ $document->cheque_no }} @if ($document->cheque_date) · {{ Format::date($document->cheque_date) }} @endif</td></tr>@endif
            @if ($foreign)<tr><td>{{ __('Rate') }}</td><td class="num">{{ Format::quantity((string) $document->exchange_rate, 8) }}</td></tr>@endif
            <tr class="grand"><td>{{ __('Amount') }}</td><td class="num">{{ $grand('amount') }}</td></tr>
        </table>
    @elseif ($shape === 'cash')
        <div class="parties">
            <div><div class="label">{{ $document instanceof \App\Models\CashBank\CashPayment ? __('Paid to') : __('Received from') }}</div><strong>{{ $document->payee ?? $document->payer ?? '—' }}</strong></div>
            <div><div class="label">{{ __('Cash / Bank') }}</div>{{ $document->bankAccount?->name }}</div>
            @if ($document->cheque_no)<div><div class="label">{{ __('Cheque / giro') }}</div>{{ $document->cheque_no }}</div>@endif
        </div>
        <table>
            <thead><tr><th>{{ __('Account') }}</th><th>{{ __('Memo') }}</th><th class="num">{{ __('Amount') }}</th></tr></thead>
            <tbody>
            @foreach ($lines as $line)
                <tr><td>{{ $line->account?->no }} {{ $line->account?->name }}</td><td>{{ $line->memo }}</td><td class="num">{{ Format::number((int) $line->amount) }}</td></tr>
            @endforeach
            </tbody>
        </table>
        <table class="totals"><tr class="grand"><td>{{ __('Total') }}</td><td class="num">{{ Format::rupiah((int) $document->amount) }}</td></tr></table>
    @elseif ($shape === 'transfer')
        <table>
            <tr><th>{{ __('From') }}</th><td>{{ $document->fromBankAccount?->name }}</td></tr>
            <tr><th>{{ __('To') }}</th><td>{{ $document->toBankAccount?->name }}</td></tr>
            <tr><th>{{ __('Amount') }}</th><td class="num" style="text-align:left">{{ Format::rupiah((int) $document->amount) }}</td></tr>
            @if ((int) $document->fees_total)<tr><th>{{ __('Fees') }}</th><td>{{ Format::rupiah((int) $document->fees_total) }}</td></tr>@endif
        </table>
    @elseif ($shape === 'journal')
        <table>
            <thead><tr><th>{{ __('Account') }}</th><th>{{ __('Memo') }}</th><th class="num">{{ __('Debit') }}</th><th class="num">{{ __('Credit') }}</th></tr></thead>
            <tbody>
            @foreach ($lines as $line)
                <tr><td>{{ $line->account?->no }} {{ $line->account?->name }}</td><td>{{ $line->memo }}</td><td class="num">{{ $line->debit ? Format::number((int) $line->debit) : '' }}</td><td class="num">{{ $line->credit ? Format::number((int) $line->credit) : '' }}</td></tr>
            @endforeach
            </tbody>
        </table>
        <table class="totals"><tr class="grand"><td>{{ __('Total') }}</td><td class="num">{{ Format::rupiah((int) $document->total) }}</td></tr></table>
    @elseif ($shape === 'stock')
        <table>
            <thead><tr>
                @if ($s['show_item_code'] ?? true)<th>{{ __('Code') }}</th>@endif
                <th>{{ __('Item') }}</th><th class="num">{{ __('Qty') }}</th>
                @if ($s['show_unit'] ?? true)<th>{{ __('Unit') }}</th>@endif
                <th>{{ __('Warehouse') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($lines as $line)
                <tr>
                    @if ($s['show_item_code'] ?? true)<td class="mono">{{ $line->item?->number }}</td>@endif
                    <td>{{ $line->item?->name }}</td>
                    <td class="num">{{ Format::quantity($line->quantity ?? $line->base_quantity) }}</td>
                    @if ($s['show_unit'] ?? true)<td>{{ $line->unit?->name }}</td>@endif
                    <td>{{ $line->warehouse?->name ?? $document->warehouse?->name }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if (($s['show_notes'] ?? true) && ($document->description ?? null))
        <div class="label">{{ __('Notes') }}</div>
        <p class="notes">{{ $document->description }}</p>
    @endif

    @if ($s['show_signature'] ?? true)
        <div class="signatures">
            <div><div class="line">{{ __('Prepared by') }}</div></div>
            <div><div class="line">{{ __('Approved by') }}</div></div>
            <div><div class="line">{{ $meta['party'] === 'customer' ? __('Received by') : __('Acknowledged by') }}</div></div>
        </div>
    @endif

    @if ($s['footer'] ?? null)
        <footer>{{ $s['footer'] }}</footer>
    @endif
</div>
</body>
</html>
