{{--
    Pesanan pembelian — the purchase order as the supplier receives it.

    The first of the three print documents that travels outward. The surat jalan
    goes with our goods; the faktur goes to our customer; this one goes to
    somebody outside the company and asks them to do something.

    Two things earn their place on it beyond the obvious:

    - The instruction to quote our PO number on their surat jalan and faktur.
      That reference is what makes the three-way match possible when the goods
      turn up. Without it somebody has to guess which order a delivery belongs
      to, and guessing is how a delivery gets matched to the wrong order.
    - Prices stated as excluding PPN. A supplier who reads the total as
      VAT-inclusive invoices for 11% less than we agreed, and the difference is
      only found at the match.
--}}
@php
    use App\Domain\Money;
    use App\Domain\Purchasing\PurchaseOrderStatus;
    use App\Domain\Terbilang;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pesanan Pembelian {{ $po->nomor }}</title>
    <style>
        /* Self-contained: this must print identically from a machine that has
           never loaded the app's stylesheet. */
        *{ box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px;
            font: 12px/1.45 ui-sans-serif, system-ui, 'Segoe UI', Roboto, Arial, sans-serif;
            color: #111;
            background: #fff;
        }
        .sheet { max-width: 800px; margin: 0 auto; }
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
        th, td { padding: 7px 8px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { border-bottom: 1.5px solid #073185; font-size: 10px; text-transform: uppercase;
             letter-spacing: .06em; color: #073185; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .kode { font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace; }
        .totals { display: flex; justify-content: flex-end; margin-top: 14px; }
        .totals table { width: 320px; margin: 0; }
        .totals td { border: none; padding: 4px 8px; }
        .totals tr.grand td { border-top: 1.5px solid #073185; font-weight: 700; font-size: 13.5px;
                              color: #073185; padding-top: 8px; }
        .terbilang { margin-top: 14px; border: 1px solid #ddd; padding: 8px 10px; font-size: 11.5px; }
        .terbilang .words { display: inline-block; font-style: italic; }
        .terbilang .words::first-letter { text-transform: uppercase; }
        /* The instructions box. Bordered in company blue because it is the part
           the supplier has to act on, not the part they skim. */
        .instruksi { margin-top: 18px; border: 1px solid #073185; padding: 10px 12px; font-size: 11.5px; }
        .instruksi ol { margin: 6px 0 0; padding-left: 20px; }
        .instruksi li { margin-bottom: 3px; }
        .stamp { margin-top: 14px; border: 2px solid #b91c1c; color: #b91c1c; padding: 8px 12px;
                 font-weight: 700; letter-spacing: .06em; text-align: center; text-transform: uppercase; }
        .sign { display: flex; gap: 24px; margin-top: 34px; }
        .sign > div { flex: 1; }
        .sign .line { margin-top: 52px; border-top: 1px solid #111; padding-top: 4px; font-size: 11px; }
        .foot { margin-top: 24px; border-top: 1px solid #ddd; padding-top: 8px; font-size: 10px; color: #666;
                display: flex; justify-content: space-between; gap: 16px; }
        .toolbar { max-width: 800px; margin: 0 auto 16px; display: flex; gap: 8px; }
        .toolbar button, .toolbar a {
            font: inherit; padding: 8px 14px; border-radius: 6px; border: 1px solid #073185;
            background: #073185; color: #fff; cursor: pointer; text-decoration: none;
        }
        .toolbar a { background: #fff; color: #073185; }
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
            <h1>Pesanan Pembelian</h1>
            <div class="num">{{ $po->nomor }}</div>
            <div class="muted">Tanggal {{ $po->tanggal_po->format('d/m/Y') }}</div>
            @if ($po->tanggal_diharapkan)
                <div class="muted">
                    Diharapkan tiba <strong>{{ $po->tanggal_diharapkan->format('d/m/Y') }}</strong>
                </div>
            @endif
        </div>
    </div>

    {{-- A closed or cancelled order can still be printed — for the file, or to
         confirm a cancellation — but it must never look like a live order. --}}
    @if ($po->status !== PurchaseOrderStatus::Dikirim)
        <div class="stamp">{{ $po->status->label() }}</div>
    @endif

    <div class="parties">
        <div>
            <div class="label">Kepada pemasok</div>
            <div><strong>{{ $po->supplier->nama }}</strong></div>
            @if ($po->supplier->nama_kontak)
                <div class="muted">u.p. {{ $po->supplier->nama_kontak }}</div>
            @endif
            @if ($po->supplier->alamat)
                <div class="muted">{{ $po->supplier->alamat }}</div>
            @endif
            @if ($po->supplier->telepon)
                <div class="muted">{{ $po->supplier->telepon }}</div>
            @endif
        </div>
        <div>
            <div class="label">Kirim ke</div>
            <div><strong>{{ $po->warehouse->nama }}</strong></div>
            <div class="muted">{{ $po->warehouse->alamat ?: config('perusahaan.kontak.alamat') }}</div>
            @if ($po->referensi_supplier)
                <div style="margin-top:6px">Referensi Anda: <strong>{{ $po->referensi_supplier }}</strong></div>
            @endif
            <div class="muted" style="margin-top:6px">
                Termin pembayaran: {{ $po->supplier->payment_terms_days }} hari
            </div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 14%">Kode</th>
                <th>Nama barang</th>
                <th class="num" style="width: 14%">Jumlah</th>
                <th class="num" style="width: 16%">Harga satuan</th>
                <th class="num" style="width: 18%">Jumlah harga</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($po->lines as $line)
                <tr>
                    <td class="kode">{{ $line->sku }}</td>
                    <td>
                        {{ trim(($line->product?->merk ?? '').' '.($line->product?->description ?? '')) ?: $line->sku }}
                        @if ($line->product?->part_number)
                            <div class="muted">P/N {{ $line->product->part_number }}</div>
                        @endif
                        @if ($line->catatan)
                            <div class="muted">{{ $line->catatan }}</div>
                        @endif
                    </td>
                    <td class="num">
                        {{ $line->ordered_qty }} {{ $line->ordered_unit->label() }}
                        {{-- Both units, because the supplier ships cartons and we
                             count pieces; leaving one out is how a delivery
                             arrives ten times too small. --}}
                        @if ($line->qty_base !== $line->ordered_qty)
                            <div class="muted">
                                = {{ number_format($line->qty_base, 0, ',', '.') }}
                                {{ $line->satuan_dasar_snapshot ?? 'PCS' }}
                            </div>
                        @endif
                    </td>
                    <td class="num">
                        {{ Money::format($line->unit_cost_rupiah) }}
                        <div class="muted">/ {{ $line->ordered_unit->label() }}</div>
                    </td>
                    <td class="num">{{ Money::format($line->line_value_rupiah) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Tidak ada baris.</td></tr>
            @endforelse
        </tbody>
    </table>

    @php $subtotal = (int) $po->lines->sum('line_value_rupiah'); @endphp

    <div class="totals">
        <table>
            <tr class="grand">
                <td>Total pesanan</td>
                <td class="num">{{ Money::format($subtotal) }}</td>
            </tr>
        </table>
    </div>

    <div class="terbilang">
        <span class="label" style="display:inline">Terbilang</span>
        <span class="words">{{ Terbilang::rupiah($subtotal) }}</span>
    </div>

    <div class="instruksi">
        <div class="label">Mohon diperhatikan</div>
        <ol>
            <li>
                Harga di atas <strong>belum termasuk PPN</strong>. PPN ditambahkan pada faktur
                sesuai ketentuan yang berlaku.
            </li>
            <li>
                Cantumkan nomor pesanan <strong>{{ $po->nomor }}</strong> pada surat jalan dan
                faktur Anda, agar penerimaan barang dan tagihan dapat kami cocokkan.
            </li>
            <li>
                Kirim barang ke alamat gudang di atas pada jam kerja
                {{ config('perusahaan.kontak.jam_operasional') }}.
            </li>
            <li>
                Barang yang tidak sesuai kode, jumlah, atau harga pada pesanan ini dapat kami
                tolak pada saat penerimaan.
            </li>
            @if ($po->catatan)
                <li>{{ $po->catatan }}</li>
            @endif
        </ol>
    </div>

    <div class="sign">
        <div>
            <div class="label">Dipesan oleh</div>
            <div class="line">
                {{ $po->sender?->name ?? config('perusahaan.nama') }}<br>
                <span class="muted">{{ config('perusahaan.nama') }}</span>
            </div>
        </div>
        <div>
            <div class="label">Diterima &amp; disetujui pemasok</div>
            <div class="line">Nama, tanggal &amp; tanda tangan</div>
        </div>
    </div>

    <div class="foot">
        <span>Pesanan ini diterbitkan secara elektronik dan sah tanpa tanda tangan basah.</span>
        <span>{{ $po->nomor }}</span>
    </div>

</div>

</body>
</html>
