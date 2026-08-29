{{--
    Penawaran — the priced offer, printed for a customer.

    Same print-first discipline as the faktur, and the same source of truth:
    every figure comes from the quote's own line snapshots, priced by the
    resolver on the day of issue. The validity line is the document's whole
    legal weight — an offer with no expiry is an offer forever, which is a
    promise nobody meant to make.
--}}
@php
    use App\Domain\Money;
    use App\Models\Quotation;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Penawaran {{ $quotation->nomor }}</title>
    <style>
        *{ box-sizing: border-box; }
        body { margin: 0; padding: 24px; font: 12px/1.45 ui-sans-serif, system-ui, 'Segoe UI', Roboto, Arial, sans-serif; color: #111; background: #fff; }
        .sheet { max-width: 820px; margin: 0 auto; }
        h1 { margin: 0 0 2px; font-size: 19px; letter-spacing: .04em; text-transform: uppercase; color: #073185; }
        .muted { color: #555; }
        .head { display: flex; justify-content: space-between; gap: 24px; border-bottom: 2px solid #073185; padding-bottom: 12px; }
        .head .firm { font-size: 15px; font-weight: 700; }
        .doc-no { text-align: right; }
        .doc-no .num { font-size: 15px; font-weight: 700; letter-spacing: .03em; }
        .parties { display: flex; gap: 24px; margin: 18px 0; }
        .parties > div { flex: 1; }
        .label { font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: #666; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; text-align: left; color: #444; border-bottom: 1.5px solid #111; padding: 6px 8px; }
        td { padding: 6px 8px; border-bottom: 1px solid #ddd; vertical-align: top; }
        .num-col { text-align: right; white-space: nowrap; }
        .totals { margin-top: 10px; margin-left: auto; width: 300px; }
        .totals td { border: none; padding: 3px 8px; }
        .totals .grand td { border-top: 2px solid #073185; font-weight: 700; font-size: 13px; }
        .validity { margin-top: 18px; padding: 10px 12px; background: #f0f5ff; border-left: 3px solid #073185; }
        .draft-mark { margin: 12px 0; padding: 8px 12px; border: 2px dashed #b91c1c; color: #b91c1c; font-weight: 700; text-align: center; letter-spacing: .1em; }
        .foot { margin-top: 28px; display: flex; justify-content: space-between; gap: 24px; }
        @media print { body { padding: 0; } .no-print { display: none; } }
    </style>
</head>
<body>
<div class="sheet">
    <div class="head">
        <div>
            <div class="firm">{{ config('perusahaan.nama') }}</div>
            <div class="muted">{{ config('perusahaan.kontak.alamat') }}, {{ config('perusahaan.kontak.kota') }}</div>
            <div class="muted">{{ config('perusahaan.kontak.telepon') }} · {{ config('perusahaan.kontak.email') }}</div>
        </div>
        <div class="doc-no">
            <h1>Penawaran</h1>
            <div class="num">{{ $quotation->nomor }}</div>
            <div class="muted">{{ $quotation->created_at->translatedFormat('j F Y') }}</div>
        </div>
    </div>

    @if ($quotation->status === Quotation::STATUS_DRAFT)
        <div class="draft-mark">DRAF — BELUM DIKIRIM KE PELANGGAN</div>
    @endif

    <div class="parties">
        <div>
            <div class="label">Kepada</div>
            <strong>{{ $quotation->company->nama }}</strong><br>
            {{ $quotation->company->alamat_kirim }}<br>
            {{ $quotation->company->kota }}
        </div>
        <div>
            <div class="label">Disiapkan oleh</div>
            {{ $quotation->creator?->name }}<br>
            <span class="muted">{{ config('perusahaan.kontak.whatsapp') }}</span>
        </div>
    </div>

    <table>
        <thead>
        <tr>
            <th>#</th><th>KODE</th><th>Nama barang</th><th>Merk</th>
            <th class="num-col">Jumlah</th>
            <th class="num-col">Harga satuan</th>
            <th class="num-col">Jumlah harga</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($quotation->lines as $line)
            <tr>
                <td>{{ $line->urutan }}</td>
                <td>{{ $line->sku }}</td>
                <td>{{ $line->description_snapshot }}</td>
                <td>{{ $line->merk_snapshot }}</td>
                <td class="num-col">{{ number_format($line->ordered_qty, 0, ',', '.') }} {{ $line->ordered_unit }}</td>
                <td class="num-col">{{ Money::format((int) $line->unit_price_rupiah) }}</td>
                <td class="num-col">{{ Money::format((int) $line->line_total_rupiah) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="num-col">{{ Money::format((int) $quotation->subtotal_rupiah) }}</td></tr>
        <tr><td>PPN</td><td class="num-col">{{ Money::format((int) $quotation->ppn_rupiah) }}</td></tr>
        <tr class="grand"><td>Total</td><td class="num-col">{{ Money::format((int) $quotation->total_rupiah) }}</td></tr>
    </table>

    <div class="validity">
        Harga di atas berlaku sampai <strong>{{ $quotation->valid_until->translatedFormat('j F Y') }}</strong>
        dan belum mengikat sebelum pesanan dikonfirmasi. Harga pada faktur mengikuti
        konfirmasi pesanan.
        @if ($quotation->catatan) <br>{{ $quotation->catatan }} @endif
    </div>

    <div class="foot">
        <div class="muted">Hormat kami,<br><br><br>{{ $quotation->creator?->name }}</div>
        <div class="muted">Disetujui,<br><br><br>{{ $quotation->company->nama_kontak ?: $quotation->company->nama }}</div>
    </div>
</div>
</body>
</html>
