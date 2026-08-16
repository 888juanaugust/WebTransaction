{{--
    Nota kredit — the faktur run backwards.

    Same shape as the invoice deliberately: the buyer's bookkeeper posts from
    both, and a document that reduces a balance should be as legible as the one
    that created it. DPP and PPN per line for the same tax reason.

    Two things the invoice does not have to say and this does. It names the
    faktur it credits, because a credit floating free of an invoice is a
    document nobody can reconcile. And it states the reason, in the words
    somebody typed — a return is an argument that was settled, and the
    settlement is part of the record.

    Red rather than the company blue. It is the one document in the set that
    means money going the other way, and a nota kredit mistaken for an invoice
    gets paid.
--}}
@php
    use App\Domain\Money;
    use App\Domain\Terbilang;
    use App\Domain\Billing\CreditNoteType;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nota kredit {{ $nota->nomor }}</title>
    <style>
        /* Self-contained, so the document prints identically from a machine
           that has never loaded the app's stylesheet. */
        *{ box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px;
            font: 12px/1.45 ui-sans-serif, system-ui, 'Segoe UI', Roboto, Arial, sans-serif;
            color: #111;
            background: #fff;
        }
        .sheet { max-width: 820px; margin: 0 auto; }
        h1 { margin: 0 0 2px; font-size: 19px; letter-spacing: .04em; text-transform: uppercase; color: #b91c1c; }
        .muted { color: #555; }
        .head { display: flex; justify-content: space-between; gap: 24px;
                border-bottom: 2px solid #b91c1c; padding-bottom: 12px; }
        .head .firm { font-size: 15px; font-weight: 700; }
        .doc-no { text-align: right; }
        .doc-no .num { font-size: 15px; font-weight: 700; letter-spacing: .03em; }
        .parties { display: flex; gap: 24px; margin: 18px 0; }
        .parties > div { flex: 1; }
        .label { font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: #666; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { padding: 6px 7px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { border-bottom: 1.5px solid #b91c1c; font-size: 9.5px; text-transform: uppercase;
             letter-spacing: .05em; color: #b91c1c; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .kode { font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace; }
        .alasan { margin: 14px 0; border-left: 3px solid #b91c1c; background: #fef2f2; padding: 8px 12px; }
        .totals { display: flex; justify-content: flex-end; margin-top: 14px; }
        .totals table { width: 320px; margin: 0; }
        .totals td { border: none; padding: 4px 7px; }
        .totals tr.grand td { border-top: 1.5px solid #b91c1c; font-weight: 700; font-size: 13.5px;
                              color: #b91c1c; padding-top: 8px; }
        .terbilang { margin-top: 14px; border: 1px solid #ddd; padding: 8px 10px; font-size: 11.5px; }
        /* Sentence case, not title case — only the first letter is raised on
           an Indonesian document. */
        .terbilang .words { display: inline-block; font-style: italic; }
        .terbilang .words::first-letter { text-transform: uppercase; }
        .note { margin-top: 16px; font-size: 10.5px; color: #555; }
        .sign { display: flex; gap: 24px; margin-top: 34px; }
        .sign > div { flex: 1; }
        .sign .line { margin-top: 52px; border-top: 1px solid #111; padding-top: 4px; font-size: 11px; }
        .foot { margin-top: 24px; border-top: 1px solid #ddd; padding-top: 8px; font-size: 10px; color: #666;
                display: flex; justify-content: space-between; gap: 16px; }
        .toolbar { max-width: 820px; margin: 0 auto 16px; display: flex; gap: 8px; }
        .toolbar button, .toolbar a {
            font: inherit; padding: 8px 14px; border-radius: 6px; border: 1px solid #b91c1c;
            background: #b91c1c; color: #fff; cursor: pointer; text-decoration: none;
        }
        .toolbar a { background: #fff; color: #b91c1c; }
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
            @if (config('perusahaan.legal.npwp'))
                <div class="muted">NPWP {{ config('perusahaan.legal.npwp') }}</div>
            @endif
        </div>
        <div class="doc-no">
            <h1>Nota kredit</h1>
            <div class="num">{{ $nota->nomor }}</div>
            <div class="muted">Tanggal {{ $nota->tanggal->format('d/m/Y') }}</div>
            <div class="muted">Atas faktur <strong>{{ $nota->invoice?->nomor }}</strong></div>
            <div class="muted">{{ $nota->jenis->label() }}</div>
        </div>
    </div>

    <div class="parties">
        <div>
            <div class="label">Kepada</div>
            <div style="font-weight:600">{{ $nota->company?->nama }}</div>
            <div class="muted">{{ $nota->company?->alamat_kirim }}</div>
            <div class="muted">{{ $nota->company?->kota }}</div>
            @if ($nota->company?->npwp)
                <div class="muted">NPWP {{ $nota->company->npwp }}</div>
            @endif
        </div>
        <div>
            <div class="label">Keterangan</div>
            <div class="muted">{{ $nota->jenis->description() }}</div>
            @if ($nota->jenis === CreditNoteType::ReturBarang && $nota->warehouse)
                <div class="muted">Barang diterima di {{ $nota->warehouse->nama }}</div>
            @endif
            @if ($nota->nomor_nota_retur)
                <div class="muted">Nota retur pembeli: {{ $nota->nomor_nota_retur }}</div>
            @endif
        </div>
    </div>

    <div class="alasan">
        <div class="label">Alasan</div>
        {{ $nota->alasan }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Kode</th>
                <th>Nama barang</th>
                <th class="num">Jumlah</th>
                <th class="num">Harga satuan</th>
                <th class="num">Nilai kredit</th>
                <th class="num">DPP</th>
                <th class="num">PPN</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($nota->lines as $line)
                <tr>
                    <td class="kode">{{ $line->sku }}</td>
                    <td>{{ $line->deskripsi ?? $line->product?->description ?? '—' }}</td>
                    <td class="num">
                        @if ($line->qty_base > 0)
                            {{ number_format($line->qty_base, 0, ',', '.') }}
                            {{ $line->product?->satuan_dasar ?? '' }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="num">
                        {{ $line->unit_price_rupiah > 0 ? Money::format((int) $line->unit_price_rupiah) : '—' }}
                    </td>
                    <td class="num">{{ Money::format((int) $line->line_total_rupiah) }}</td>
                    <td class="num">{{ Money::format((int) $line->dpp_rupiah) }}</td>
                    <td class="num">{{ Money::format((int) $line->ppn_rupiah) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="4" style="text-align:right">Jumlah</th>
                <th class="num">{{ Money::format((int) $nota->subtotal_rupiah) }}</th>
                <th class="num">{{ Money::format((int) $nota->dpp_rupiah) }}</th>
                <th class="num">{{ Money::format((int) $nota->ppn_rupiah) }}</th>
            </tr>
        </tfoot>
    </table>

    <div class="totals">
        <table>
            <tr>
                <td>Nilai kredit</td>
                <td class="num">{{ Money::format((int) $nota->subtotal_rupiah) }}</td>
            </tr>
            <tr>
                <td>PPN</td>
                <td class="num">{{ Money::format((int) $nota->ppn_rupiah) }}</td>
            </tr>
            <tr class="grand">
                <td>Total dikreditkan</td>
                <td class="num">{{ Money::format((int) $nota->total_rupiah) }}</td>
            </tr>
        </table>
    </div>

    <div class="terbilang">
        <span class="label" style="display:inline">Terbilang</span>
        <span class="words">{{ Terbilang::rupiah((int) $nota->total_rupiah) }}</span>
    </div>

    <div class="note">
        Nota kredit ini mengurangi jumlah yang harus dibayar atas faktur
        <strong>{{ $nota->invoice?->nomor }}</strong>. Bukan penerimaan uang tunai dan bukan bukti
        pembayaran.
        @if ((int) $nota->ppn_rupiah > 0)
            PPN dihitung per baris dengan DPP 11/12 dari nilai kredit, sesuai PMK 131/2024.
            Dokumen ini bukan Faktur Pajak.
        @endif
    </div>

    <div class="sign">
        <div>
            <div class="label">Diterbitkan oleh</div>
            <div class="line">{{ $nota->postedBy?->name ?? '' }}</div>
        </div>
        <div>
            <div class="label">Diterima oleh</div>
            <div class="line">Nama jelas dan tanggal</div>
        </div>
    </div>

    <div class="foot">
        <div>{{ config('perusahaan.nama') }}</div>
        <div>{{ $nota->nomor }} · dicetak {{ now()->format('d/m/Y H:i') }}</div>
    </div>

</div>
</body>
</html>
