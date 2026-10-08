@php
    use App\Domain\Shared\Format;
    use App\Filament\Support\Months;
    $employee = $slip['employee'];
    $letterhead = $company->letterhead();
    $a = $slip['annual'];
    $r = $slip['rows'];
    $rows = [
        ['1', __('Salary, pension or old-age benefit'), $r['salary']],
        ['2', __('Income tax allowance'), $r['tax_allowance']],
        ['3', __('Other allowances, overtime and the like'), $r['other_allowance']],
        ['4', __('Honoraria and similar rewards'), $r['honorarium']],
        ['5', __('Insurance premiums paid by the employer'), $r['insurance']],
        ['6', __('Benefits in kind taxed under Art. 21'), $r['in_kind']],
        ['7', __('Bonus, gratuity, incentive, holiday allowance (THR)'), $r['bonus']],
        ['8', __('Gross income (1 to 7)'), $a['gross'], true],
        ['9', __('Occupational cost'), $a['biaya_jabatan']],
        ['10', __('Pension and old-age contributions'), $slip['pension']],
        ['11', __('Zakat or religious donations through the employer'), $slip['zakat']],
        ['12', __('Deductions (9 to 11)'), $a['biaya_jabatan'] + $slip['pension'] + $slip['zakat'], true],
        ['13', __('Net income (8 less 12)'), $a['net'], true],
        ['14', __('Net income from an earlier employer'), $a['previous_net']],
        ['15', __('Net income for the year'), $a['net_year'], true],
        ['16', __('Non-taxable income (PTKP)'), $a['ptkp']],
        ['17', __('Taxable income for the year'), $a['pkp']],
        ['18', __('Income tax on taxable income for the year'), $a['tax']],
        ['19', __('Income tax withheld by an earlier employer'), $slip['previous_tax']],
        ['20', __('Income tax due for the year'), $slip['tax_due'], true],
        ['21', __('Income tax withheld this year'), $slip['withheld']],
    ];
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>A1 {{ $slip['year'] }} {{ $employee->name }}</title>
    <style>
        @page { size: A4 portrait; margin: 14mm; }
        * { box-sizing: border-box; }
        body { font-family: "Geist Variable", Geist, "Helvetica Neue", Arial, sans-serif; font-size: 10.5pt; color: #111827; margin: 0; }
        .sheet { max-width: 190mm; margin: 0 auto; padding: 10mm 0; }
        header { display: flex; justify-content: space-between; border-bottom: 2px solid #111827; padding-bottom: 8px; margin-bottom: 14px; }
        h1 { font-size: 15pt; margin: 0 0 2px; }
        header p { margin: 0; font-size: 9.5pt; color: #374151; }
        .title { text-align: right; }
        .title strong { font-size: 16pt; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; margin-bottom: 14px; font-size: 9.5pt; }
        .label { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; }
        td.no { width: 28px; color: #6b7280; }
        td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        tr.sum td { font-weight: 700; border-bottom: 1px solid #111827; }
        footer { margin-top: 18px; font-size: 8.5pt; color: #6b7280; }
        .toolbar { position: fixed; top: 10px; right: 10px; }
        .toolbar button { font: inherit; padding: 8px 14px; border-radius: 10px; border: 0; background: #2f5bea; color: #fff; cursor: pointer; }
        @media print { .toolbar { display: none; } .sheet { padding: 0; } }
    </style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">{{ __('Print') }}</button></div>
<div class="sheet">
    <header>
        <div>
            <h1>{{ $letterhead['name'] }}</h1>
            @if ($letterhead['npwp'])<p>NPWP {{ $letterhead['npwp'] }}</p>@endif
            @if ($letterhead['address'])<p>{{ $letterhead['address'] }}</p>@endif
        </div>
        <div class="title">
            <strong>{{ __('Withholding slip A1') }}</strong>
            <p>{{ __('Income tax Art. 21, permanent employee') }}</p>
            <p>{{ __('Tax year :year, :from – :to', ['year' => $slip['year'], 'from' => Months::name($slip['month_start']), 'to' => Months::name($slip['month_end'])]) }}</p>
        </div>
    </header>
    <div class="grid">
        <div><span class="label">{{ __('Employee') }}</span> {{ $employee->name }}</div>
        <div><span class="label">{{ __('Tax ID / NIK') }}</span> {{ $employee->npwp_no ?: $employee->nik_no }}</div>
        <div><span class="label">{{ __('Position') }}</span> {{ $employee->position }}</div>
        <div><span class="label">{{ __('PTKP status') }}</span> {{ $slip['ptkp_status'] }}</div>
        <div><span class="label">{{ __('Tax object code') }}</span> {{ config('pajak.pph21.object_code') }}</div>
        <div><span class="label">{{ __('Months') }}</span> {{ $a['months'] }}</div>
    </div>
    <table>
        @foreach ($rows as $row)
            <tr @class(['sum' => $row[3] ?? false])><td class="no">{{ $row[0] }}</td><td>{{ $row[1] }}</td><td class="num">{{ Format::number((int) $row[2]) }}</td></tr>
        @endforeach
    </table>
    @unless ($slip['complete'])
        <footer>{{ __('The year is still running: these figures are the year so far.') }}</footer>
    @endunless
    <footer>{{ __('Figures in :symbol.', ['symbol' => Format::symbol()]) }} {{ Format::date($slip['date']) }}</footer>
</div>
</body>
</html>
