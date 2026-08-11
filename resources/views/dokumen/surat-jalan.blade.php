{{--
    Surat jalan — the delivery note that goes with the goods.

    Print-first. The screen version exists only so somebody can check it before
    hitting Ctrl+P; every decision here is about what comes out of a printer.

    NO PRICES. A surat jalan is handed to a driver and then to whoever signs for
    the delivery, and neither is party to what this customer pays. The order's
    money lives on the faktur, which is a different document with a different
    audience.

    Deliberately plain: black on white, hairline rules, no brand fills. Colour
    costs ink on a shared warehouse printer and adds nothing a packer needs.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Surat Jalan {{ $order->nomor }}</title>
    <style>
        /* Self-contained: this page must print identically whether or not the
           app's stylesheet was cached, and on a machine that has never loaded
           the panel. */
        *{ box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px;
            font: 12px/1.45 ui-sans-serif, system-ui, 'Segoe UI', Roboto, Arial, sans-serif;
            color: #111;
            background: #fff;
        }
        .sheet { max-width: 760px; margin: 0 auto; }
        h1 { margin: 0 0 2px; font-size: 19px; letter-spacing: .04em; text-transform: uppercase; }
        .muted { color: #555; }
        .head { display: flex; justify-content: space-between; gap: 24px; border-bottom: 2px solid #111; padding-bottom: 12px; }
        .head .firm { font-size: 15px; font-weight: 700; }
        .doc-no { text-align: right; }
        .doc-no .num { font-size: 15px; font-weight: 700; letter-spacing: .03em; }
        .parties { display: flex; gap: 24px; margin: 18px 0; }
        .parties > div { flex: 1; }
        .label { font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: #666; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { padding: 7px 8px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { border-bottom: 1.5px solid #111; font-size: 10px; text-transform: uppercase; letter-spacing: .06em; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .kode { font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace; }
        /* A box the receiver ticks as each line is checked off. */
        .tick { width: 26px; }
        .tick span { display: inline-block; width: 13px; height: 13px; border: 1px solid #999; }
        .sign { display: flex; gap: 24px; margin-top: 40px; }
        .sign > div { flex: 1; }
        .sign .line { margin-top: 56px; border-top: 1px solid #111; padding-top: 4px; font-size: 11px; }
        .note { margin-top: 22px; font-size: 11px; }
        .foot { margin-top: 26px; border-top: 1px solid #ddd; padding-top: 8px; font-size: 10px; color: #666;
                display: flex; justify-content: space-between; }
        .toolbar { max-width: 760px; margin: 0 auto 16px; display: flex; gap: 8px; }
        .toolbar button, .toolbar a {
            font: inherit; padding: 8px 14px; border-radius: 6px; border: 1px solid #2b3467;
            background: #2b3467; color: #fff; cursor: pointer; text-decoration: none;
        }
        .toolbar a { background: #fff; color: #2b3467; }
        @media print {
            body { padding: 0; }
            .toolbar { display: none; }
            /* Repeat the header on every sheet of a long order. */
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
            <div class="muted">
                {{ config('perusahaan.kontak.telepon') }}
                @if (config('perusahaan.legal.npwp'))
                    · NPWP {{ config('perusahaan.legal.npwp') }}
                @endif
            </div>
        </div>
        <div class="doc-no">
            <h1>Surat Jalan</h1>
            <div class="num">{{ $order->nomor }}</div>
            <div class="muted">Tanggal cetak {{ now()->format('d/m/Y H:i') }}</div>
        </div>
    </div>

    <div class="parties">
        <div>
            <div class="label">Dikirim kepada</div>
            <div><strong>{{ $order->company->nama }}</strong></div>
            <div class="muted">{{ $order->company->alamat }}</div>
            @if ($order->company->telepon)
                <div class="muted">{{ $order->company->telepon }}</div>
            @endif
        </div>
        <div>
            <div class="label">Detail pengiriman</div>
            <div>Gudang asal: <strong>{{ $order->warehouse->nama }}</strong></div>
            @if ($order->po_pelanggan)
                <div>Nomor PO pelanggan: <strong>{{ $order->po_pelanggan }}</strong></div>
            @endif
            <div class="muted">Order dibuat {{ $order->created_at->format('d/m/Y') }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="tick"></th>
                <th style="width: 15%">Kode</th>
                <th>Nama barang</th>
                <th class="num" style="width: 16%">Jumlah</th>
                <th class="num" style="width: 14%">Satuan dasar</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($order->lines as $line)
                <tr>
                    <td class="tick"><span></span></td>
                    <td class="kode">{{ $line->sku }}</td>
                    <td>
                        {{-- Snapshots first: the note describes what was ordered
                             then, not what the catalogue says today. --}}
                        {{ trim(($line->merk_snapshot ?? $line->product?->merk ?? '')
                            .' '.($line->description_snapshot ?? $line->product?->description ?? '')) ?: $line->sku }}
                        @if ($line->product?->part_number)
                            <div class="muted">P/N {{ $line->product->part_number }}</div>
                        @endif
                    </td>
                    <td class="num">
                        {{ $line->ordered_qty }} {{ $line->ordered_unit->label() }}
                    </td>
                    <td class="num">
                        {{ $line->qty_base }} {{ $line->satuan_dasar_snapshot ?? $line->product?->satuan_dasar }}
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Tidak ada baris.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="note">
        Total <strong>{{ $order->lines->count() }}</strong> jenis barang,
        <strong>{{ $order->lines->sum('qty_base') }}</strong> unit dasar.
    </div>

    @if ($order->catatan)
        <div class="note"><span class="label">Catatan</span><br>{{ $order->catatan }}</div>
    @endif

    <div class="sign">
        <div>
            <div class="label">Disiapkan oleh</div>
            <div class="line">Nama &amp; tanda tangan gudang</div>
        </div>
        <div>
            <div class="label">Pengirim / sopir</div>
            <div class="line">Nama &amp; tanda tangan</div>
        </div>
        <div>
            <div class="label">Diterima oleh</div>
            <div class="line">Nama, tanggal &amp; tanda tangan penerima</div>
        </div>
    </div>

    <div class="foot">
        <span>Barang yang sudah diterima dalam keadaan baik tidak dapat dikembalikan tanpa kesepakatan tertulis.</span>
        <span>{{ $order->nomor }}</span>
    </div>

</div>

</body>
</html>
