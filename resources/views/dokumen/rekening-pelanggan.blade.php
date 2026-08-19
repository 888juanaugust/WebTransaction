{{--
    Rekening koran pelanggan — the statement a customer gets before they pay.

    The sixth print document, and the only one that is a *report* rather than a
    record of one transaction. Everything else here is issued once and never
    changes; this is a window onto an account and is reissued every month.

    Blue rather than the red used by the nota kredit and nota retur. Those are
    documents about money coming back; this one is an account of what is owed,
    and colouring it like a credit note invites exactly the wrong first
    impression.

    What it does not carry: any cost, any margin, and any mention of the
    customer's credit limit. The first two are never a customer's business, and
    the third is ours to decide rather than theirs to negotiate against.
--}}
@php
    use App\Domain\Money;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rekening koran {{ $company->nama }}</title>
    <style>
        *{ box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px;
            font: 12px/1.45 ui-sans-serif, system-ui, 'Segoe UI', Roboto, Arial, sans-serif;
            color: #111;
            background: #fff;
        }
        .sheet { max-width: 820px; margin: 0 auto; }
        h1 { margin: 0 0 2px; font-size: 19px; letter-spacing: .04em; text-transform: uppercase; color: #1d4ed8; }
        .muted { color: #555; }
        .head { display: flex; justify-content: space-between; gap: 24px;
                border-bottom: 2px solid #1d4ed8; padding-bottom: 12px; }
        .head .firm { font-size: 15px; font-weight: 700; }
        .doc-no { text-align: right; }
        .parties { display: flex; gap: 24px; margin: 18px 0; }
        .parties > div { flex: 1; }
        .label { font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: #666; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { padding: 6px 7px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { border-bottom: 1.5px solid #1d4ed8; font-size: 9.5px; text-transform: uppercase;
             letter-spacing: .05em; color: #1d4ed8; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .kode { font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace; }
        tr.opening td { background: #f8fafc; font-weight: 600; }
        tr.closing td { border-top: 1.5px solid #1d4ed8; border-bottom: none;
                        font-weight: 700; font-size: 13px; color: #1d4ed8; padding-top: 9px; }
        .catatan { margin-top: 16px; border-left: 3px solid #1d4ed8; background: #eff6ff;
                   padding: 8px 12px; font-size: 11px; }
        .catatan p { margin: 0 0 5px; }
        .catatan p:last-child { margin-bottom: 0; }
        .note { margin-top: 16px; font-size: 10.5px; color: #555; }
        .foot { margin-top: 24px; border-top: 1px solid #ddd; padding-top: 8px; font-size: 10px; color: #666;
                display: flex; justify-content: space-between; gap: 16px; }
        .toolbar { max-width: 820px; margin: 0 auto 16px; display: flex; gap: 8px; }
        .toolbar button, .toolbar a {
            font: inherit; padding: 8px 14px; border-radius: 6px; border: 1px solid #1d4ed8;
            background: #1d4ed8; color: #fff; cursor: pointer; text-decoration: none;
        }
        .toolbar a { background: #fff; color: #1d4ed8; }
        @media print {
            body { padding: 0; }
            .toolbar { display: none; }
            thead { display: table-header-group; }
            tr { break-inside: avoid; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <button type="button" onclick="window.print()">Cetak</button>
    <a href="{{ url()->previous() }}">Kembali</a>
</div>

<div class="sheet">

    <div class="head">
        <div>
            @if (\App\Support\Branding::hasLogo())
                <img src="{{ \App\Support\Branding::logoUrl() }}" alt="" style="height:34px;margin-bottom:6px">
            @endif
            <div class="firm">{{ config('perusahaan.nama') }}</div>
            <div class="muted">{{ config('perusahaan.kontak.alamat') }}</div>
            <div class="muted">{{ config('perusahaan.kontak.telepon') }}</div>
        </div>
        <div class="doc-no">
            <h1>Rekening koran</h1>
            <div class="muted">{{ $period->label }}</div>
            <div class="muted">Dicetak {{ now()->format('d/m/Y') }}</div>
        </div>
    </div>

    <div class="parties">
        <div>
            <div class="label">Kepada</div>
            <div><strong>{{ $company->nama }}</strong></div>
            @if ($company->alamat_kirim)
                <div class="muted">{{ $company->alamat_kirim }}</div>
            @endif
            @if ($company->kota)
                <div class="muted">{{ $company->kota }}</div>
            @endif
        </div>
        <div>
            <div class="label">Syarat pembayaran</div>
            <div>{{ $company->payment_terms_days }} hari sejak tanggal faktur</div>
            @if ($company->nama_kontak)
                <div class="muted">u.p. {{ $company->nama_kontak }}</div>
            @endif
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:80px">Tanggal</th>
                <th style="width:130px">Dokumen</th>
                <th>Keterangan</th>
                <th class="num" style="width:110px">Tagihan</th>
                <th class="num" style="width:110px">Pembayaran</th>
                <th class="num" style="width:120px">Saldo</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($table->rows as $row)
                <tr @class(['opening' => $loop->first])>
                    <td>{{ $row['tanggal']?->format('d/m/Y') }}</td>
                    <td class="kode">{{ $row['dokumen'] }}</td>
                    <td>{{ $row['keterangan'] }}</td>
                    <td class="num">{{ $row['tagihan'] !== null ? Money::format($row['tagihan']) : '' }}</td>
                    <td class="num">{{ $row['pembayaran'] !== null ? Money::format($row['pembayaran']) : '' }}</td>
                    <td class="num">{{ Money::format($row['saldo']) }}</td>
                </tr>
            @endforeach

            <tr class="closing">
                <td colspan="5">Saldo terutang per {{ $period->to->format('d/m/Y') }}</td>
                <td class="num">{{ Money::format($table->totals['saldo']) }}</td>
            </tr>
        </tbody>
    </table>

    @if ($table->catatan !== [])
        <div class="catatan">
            @foreach ($table->catatan as $catatan)
                <p>{{ $catatan }}</p>
            @endforeach
        </div>
    @endif

    <div class="note">
        Mohon dicocokkan dengan pembukuan Anda. Kalau ada selisih, hubungi kami dengan
        menyebutkan nomor dokumennya sebelum melakukan pembayaran.
    </div>

    <div class="foot">
        <div>{{ config('perusahaan.nama') }}</div>
        <div>Rekening koran ini dicetak dari sistem dan sah tanpa tanda tangan.</div>
    </div>

</div>

</body>
</html>
