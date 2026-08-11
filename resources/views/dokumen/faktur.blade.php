{{--
    Faktur — the invoice handed to the customer.

    The surat jalan's opposite number. That document carries no money because it
    is handed to a driver; this one carries nothing but money, because it is
    handed to the buyer's bookkeeper.

    DPP and PPN appear per line, not only on the total. That is a tax rule, not
    a layout preference: under PMK 131/2024 the DPP is 11/12 of the selling
    price, and summing rounded lines is not the same number as rounding a summed
    total. The figures here come from the invoice's own snapshots — the price
    list is never consulted when printing an old invoice.

    Print-first, like the surat jalan, but this one earns a little colour: the
    header rule and the total, and nothing else. It is read at a desk, not on a
    warehouse printer.
--}}
@php
    use App\Domain\Money;
    use App\Domain\Terbilang;
    use App\Models\Invoice;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Faktur {{ $invoice->nomor }}</title>
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
        h1 { margin: 0 0 2px; font-size: 19px; letter-spacing: .04em; text-transform: uppercase; color: #073185; }
        .muted { color: #555; }
        .head { display: flex; justify-content: space-between; gap: 24px;
                border-bottom: 2px solid #073185; padding-bottom: 12px; }
        .head .firm { font-size: 15px; font-weight: 700; }
        .doc-no { text-align: right; }
        .doc-no .num { font-size: 15px; font-weight: 700; letter-spacing: .03em; }
        .parties { display: flex; gap: 24px; margin: 18px 0; }
        .parties > div { flex: 1; }
        .label { font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: #666; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { padding: 6px 7px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { border-bottom: 1.5px solid #073185; font-size: 9.5px; text-transform: uppercase;
             letter-spacing: .05em; color: #073185; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .kode { font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace; }
        .totals { display: flex; justify-content: flex-end; margin-top: 14px; }
        .totals table { width: 320px; margin: 0; }
        .totals td { border: none; padding: 4px 7px; }
        .totals tr.grand td { border-top: 1.5px solid #073185; font-weight: 700; font-size: 13.5px;
                              color: #073185; padding-top: 8px; }
        .terbilang { margin-top: 14px; border: 1px solid #ddd; padding: 8px 10px; font-size: 11.5px; }
        /* Sentence case, not title case. `capitalize` would render "Dua Puluh
           Tujuh Juta Tujuh Ratus …", which is not how the amount is written on
           an Indonesian invoice — only the first letter is raised. */
        .terbilang .words { display: inline-block; font-style: italic; }
        .terbilang .words::first-letter { text-transform: uppercase; }
        .pay { margin-top: 18px; border: 1px solid #073185; padding: 10px 12px; }
        .pay .va { font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace;
                   font-size: 16px; font-weight: 700; letter-spacing: .06em; }
        .lunas { margin-top: 18px; border: 1px solid #0a7f4b; color: #0a7f4b; padding: 10px 12px; font-weight: 600; }
        .tax-note { margin-top: 16px; font-size: 10.5px; color: #555; }
        .sign { display: flex; gap: 24px; margin-top: 34px; }
        .sign > div { flex: 1; }
        .sign .line { margin-top: 52px; border-top: 1px solid #111; padding-top: 4px; font-size: 11px; }
        .foot { margin-top: 24px; border-top: 1px solid #ddd; padding-top: 8px; font-size: 10px; color: #666;
                display: flex; justify-content: space-between; gap: 16px; }
        .toolbar { max-width: 820px; margin: 0 auto 16px; display: flex; gap: 8px; }
        .toolbar button, .toolbar a {
            font: inherit; padding: 8px 14px; border-radius: 6px; border: 1px solid #073185;
            background: #073185; color: #fff; cursor: pointer; text-decoration: none;
        }
        .toolbar a { background: #fff; color: #073185; }
        @media print {
            body { padding: 0; }
            .toolbar { display: none; }
            /* Repeat the column headers on every sheet of a long invoice. */
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
            <h1>Faktur</h1>
            <div class="num">{{ $invoice->nomor }}</div>
            <div class="muted">Tanggal faktur {{ $invoice->issued_on->format('d/m/Y') }}</div>
            <div class="muted">Jatuh tempo <strong>{{ $invoice->due_date->format('d/m/Y') }}</strong></div>
            @if ($invoice->status === Invoice::STATUS_VOID)
                <div style="margin-top:6px;font-weight:700;color:#b91c1c">DIBATALKAN</div>
            @endif
        </div>
    </div>

    <div class="parties">
        <div>
            <div class="label">Ditagihkan kepada</div>
            {{-- The tax identity as snapshotted at issue, not as the customer
                 record reads today. A faktur has to show what was true then. --}}
            <div><strong>{{ $invoice->nama_wajib_pajak ?? $invoice->company->nama }}</strong></div>
            <div class="muted">{{ $invoice->alamat_pajak ?? $invoice->company->alamat_kirim }}</div>
            <div class="muted">NPWP {{ $invoice->npwp ?: '—' }}</div>
        </div>
        <div>
            <div class="label">Referensi</div>
            @if ($invoice->order)
                <div>Pesanan: <strong>{{ $invoice->order->nomor }}</strong></div>
                @if ($invoice->order->po_pelanggan)
                    <div>Nomor PO pelanggan: <strong>{{ $invoice->order->po_pelanggan }}</strong></div>
                @endif
                @if ($invoice->order->warehouse)
                    <div class="muted">Dikirim dari {{ $invoice->order->warehouse->nama }}</div>
                @endif
            @endif
            <div class="muted">Kode transaksi {{ $invoice->kode_transaksi }}</div>
            @if ($invoice->nsfp)
                <div>NSFP: <strong>{{ $invoice->nsfp }}</strong></div>
            @endif
        </div>
    </div>

    <table>
        <thead>
            <tr>
                {{-- Column order is chosen so every money column foots to a row
                     in the totals block below: harga jual sums to the subtotal,
                     DPP to the DPP, PPN to the PPN. An invoice whose columns do
                     not add up is an invoice somebody has to phone about. --}}
                <th style="width: 12%">Kode</th>
                <th>Nama barang</th>
                <th class="num" style="width: 9%">Jumlah</th>
                <th class="num" style="width: 12%">Harga satuan</th>
                <th class="num" style="width: 10%">Diskon</th>
                <th class="num" style="width: 13%">Harga jual</th>
                <th class="num" style="width: 12%">DPP</th>
                <th class="num" style="width: 11%">PPN</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td class="kode">{{ $line->sku }}</td>
                    <td>
                        {{-- Snapshots first: this describes what was sold then,
                             not what the catalogue calls it today. --}}
                        {{ trim(($line->merk_snapshot ?? $line->product?->merk ?? '')
                            .' '.($line->description_snapshot ?? $line->product?->description ?? '')) ?: $line->sku }}
                        @if ($line->product?->part_number)
                            <div class="muted">P/N {{ $line->product->part_number }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $line->ordered_qty }} {{ $line->ordered_unit->label() }}</td>
                    <td class="num">{{ Money::format(Money::roundToRupiah($line->unit_price_rupiah)) }}</td>
                    <td class="num">{{ $line->discount_rupiah ? '−'.Money::format((int) $line->discount_rupiah) : '—' }}</td>
                    <td class="num">{{ Money::format((int) $line->line_total_rupiah) }}</td>
                    <td class="num">{{ Money::format((int) $line->dpp_rupiah) }}</td>
                    <td class="num">{{ Money::format((int) $line->ppn_rupiah) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">Tidak ada baris.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="totals">
        <table>
            {{-- Only when there is one, and shown *before* harga jual: the
                 subtotal on this invoice is already net of discount, and
                 subtracting it a second time is how a customer talks themselves
                 into paying the wrong figure. --}}
            @if ($invoice->discount_rupiah)
                <tr>
                    <td>Sebelum diskon</td>
                    <td class="num">{{ Money::format($invoice->subtotal_rupiah + $invoice->discount_rupiah) }}</td>
                </tr>
                <tr>
                    <td>Diskon</td>
                    <td class="num">−{{ Money::format($invoice->discount_rupiah) }}</td>
                </tr>
            @endif
            <tr>
                <td>Harga jual</td>
                <td class="num">{{ Money::format($invoice->subtotal_rupiah) }}</td>
            </tr>
            <tr>
                <td>DPP (11/12 × harga jual)</td>
                <td class="num">{{ Money::format($invoice->dpp_rupiah) }}</td>
            </tr>
            <tr>
                <td>PPN 12%</td>
                <td class="num">{{ Money::format($invoice->ppn_rupiah) }}</td>
            </tr>
            <tr class="grand">
                <td>Total tagihan</td>
                <td class="num">{{ Money::format($invoice->total_rupiah) }}</td>
            </tr>
        </table>
    </div>

    {{-- The line the customer's bookkeeper checks the digits against. --}}
    <div class="terbilang">
        <span class="label" style="display:inline">Terbilang</span>
        <span class="words">{{ Terbilang::rupiah($invoice->total_rupiah) }}</span>
    </div>

    @if ($outstanding > 0 && $invoice->status !== Invoice::STATUS_VOID)
        <div class="pay">
            <div class="label">Pembayaran</div>
            @if ($virtualAccount)
                <div>Transfer ke Virtual Account {{ $virtualAccount->bank_code }} atas nama
                    {{ config('perusahaan.nama') }}:</div>
                <div class="va">{{ $virtualAccount->account_number }}</div>
            @else
                <div>Hubungi kami untuk nomor Virtual Account pembayaran.</div>
            @endif
            <div style="margin-top:6px">
                Jumlah yang harus dibayar:
                <strong>{{ Money::format($outstanding) }}</strong>
                @if ($outstanding !== $invoice->total_rupiah)
                    <span class="muted">(sudah dibayar {{ Money::format($invoice->total_rupiah - $outstanding) }})</span>
                @endif
            </div>
            <div class="muted" style="margin-top:4px">
                Nomor VA bersifat tetap dan dapat dipakai untuk setiap pembayaran.
            </div>
        </div>
    @elseif ($invoice->status !== Invoice::STATUS_VOID)
        <div class="lunas">LUNAS — faktur ini sudah dibayar penuh.</div>
    @endif

    {{--
        Said plainly, because the customer will otherwise assume. This is the
        commercial invoice; the Faktur Pajak is issued through Coretax and
        carries an NSFP, which is printed above once it exists.
    --}}
    <div class="tax-note">
        Faktur komersial. PPN dihitung per baris dengan DPP nilai lain 11/12 × harga jual
        sesuai PMK 131/2024, kode transaksi {{ $invoice->kode_transaksi }}.
        @if ($invoice->nsfp)
            Faktur Pajak diterbitkan dengan NSFP {{ $invoice->nsfp }}.
        @else
            Faktur Pajak akan diterbitkan terpisah melalui Coretax.
        @endif
    </div>

    <div class="sign">
        <div>
            <div class="label">Hormat kami</div>
            <div class="line">{{ config('perusahaan.nama') }}</div>
        </div>
        <div>
            <div class="label">Diterima oleh</div>
            <div class="line">Nama, tanggal &amp; tanda tangan</div>
        </div>
    </div>

    <div class="foot">
        <span>Pembayaran setelah tanggal jatuh tempo dapat dikenakan denda keterlambatan
              sesuai syarat penjualan yang berlaku.</span>
        <span>{{ $invoice->nomor }}</span>
    </div>

</div>

</body>
</html>
