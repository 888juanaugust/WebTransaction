{{--
    Nota retur — the document that goes back with the goods.

    The fifth print document, and the second that travels outward: the pesanan
    pembelian asks a supplier to send something, and this one tells them we are
    sending it back, why, and what we expect to be credited for it.

    Under the PPN rules the nota retur is issued by the *buyer* to the seller,
    which makes this our document rather than a copy of theirs. So it carries
    our number, our NPWP, and the tax the return reverses — the supplier's
    bookkeeper posts from it, and their credit note comes back against it.

    Two things it deliberately does not show, both of which are on the screen it
    was printed from. It does not show what the goods were carried at in our
    inventory, because that is our moving average and none of the supplier's
    business. And it does not show the split between reducing a debt and
    unwinding an accrual, because that is bookkeeping about our books: the
    supplier's obligation is the total, however it lands on our side.

    Red like the nota kredit, and for the same reason — this is a document
    about money going the other way, and one mistaken for a purchase order gets
    filled.
--}}
@php
    use App\Domain\Money;
    use App\Domain\Terbilang;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nota retur {{ $retur->nomor }}</title>
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
            <h1>Nota retur</h1>
            <div class="num">{{ $retur->nomor }}</div>
            <div class="muted">Tanggal {{ $retur->tanggal->format('d/m/Y') }}</div>
            <div class="muted">Atas penerimaan <strong>{{ $retur->goodsReceipt?->nomor }}</strong></div>
            @if ($retur->goodsReceipt?->nomor_surat_jalan_supplier)
                <div class="muted">Surat jalan {{ $retur->goodsReceipt->nomor_surat_jalan_supplier }}</div>
            @endif
        </div>
    </div>

    <div class="parties">
        <div>
            <div class="label">Kepada</div>
            <div style="font-weight:600">{{ $retur->supplier?->nama }}</div>
            <div class="muted">{{ $retur->supplier?->alamat }}</div>
            @if ($retur->supplier?->nama_kontak)
                <div class="muted">u.p. {{ $retur->supplier->nama_kontak }}</div>
            @endif
            @if ($retur->supplier?->npwp)
                <div class="muted">NPWP {{ $retur->supplier->npwp }}</div>
            @endif
        </div>
        <div>
            <div class="label">Keterangan</div>
            <div class="muted">Barang dikembalikan dari {{ $retur->warehouse?->nama }}.</div>
            @if ($retur->goodsReceipt?->tanggal_terima)
                <div class="muted">Diterima {{ $retur->goodsReceipt->tanggal_terima->format('d/m/Y') }}.</div>
            @endif
            @if ($retur->nomor_nota_kredit_supplier)
                <div class="muted">Nota kredit pemasok: {{ $retur->nomor_nota_kredit_supplier }}</div>
            @endif
        </div>
    </div>

    <div class="alasan">
        <div class="label">Alasan retur</div>
        {{ $retur->alasan }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Kode</th>
                <th>Nama barang</th>
                <th class="num">Jumlah</th>
                <th class="num">Nilai</th>
                <th class="num">DPP</th>
                <th class="num">PPN</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($retur->lines as $line)
                @php
                    // What the supplier is being asked to credit for this line:
                    // what they billed, or — where they had not billed yet —
                    // what the delivery was received at. Deliberately not the
                    // inventory figure, which is our moving average.
                    $nilai = (int) $line->nilai_ditagih_rupiah + (int) $line->nilai_belum_ditagih_rupiah;
                @endphp
                <tr>
                    <td class="kode">{{ $line->sku }}</td>
                    <td>{{ $line->deskripsi ?? $line->product?->description ?? '—' }}</td>
                    <td class="num">
                        {{ number_format((int) $line->qty_base, 0, ',', '.') }}
                        {{ $line->product?->satuan_dasar ?? '' }}
                    </td>
                    <td class="num">{{ Money::format($nilai) }}</td>
                    <td class="num">{{ Money::format((int) $line->dpp_rupiah) }}</td>
                    <td class="num">{{ Money::format((int) $line->ppn_rupiah) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3" style="text-align:right">Jumlah</th>
                <th class="num">
                    {{ Money::format((int) $retur->nilai_ditagih_rupiah + (int) $retur->nilai_belum_ditagih_rupiah) }}
                </th>
                <th class="num">{{ Money::format((int) $retur->dpp_rupiah) }}</th>
                <th class="num">{{ Money::format((int) $retur->ppn_rupiah) }}</th>
            </tr>
        </tfoot>
    </table>

    <div class="totals">
        <table>
            <tr>
                <td>Nilai barang diretur</td>
                <td class="num">
                    {{ Money::format((int) $retur->nilai_ditagih_rupiah + (int) $retur->nilai_belum_ditagih_rupiah) }}
                </td>
            </tr>
            <tr>
                <td>PPN atas bagian yang sudah ditagih</td>
                <td class="num">{{ Money::format((int) $retur->ppn_rupiah) }}</td>
            </tr>
            <tr class="grand">
                <td>Total diretur</td>
                <td class="num">
                    {{ Money::format(
                        (int) $retur->nilai_ditagih_rupiah
                        + (int) $retur->nilai_belum_ditagih_rupiah
                        + (int) $retur->ppn_rupiah
                    ) }}
                </td>
            </tr>
        </table>
    </div>

    <div class="terbilang">
        <span class="label" style="display:inline">Terbilang</span>
        <span class="words">
            {{ Terbilang::rupiah(
                (int) $retur->nilai_ditagih_rupiah
                + (int) $retur->nilai_belum_ditagih_rupiah
                + (int) $retur->ppn_rupiah
            ) }}
        </span>
    </div>

    <div class="note">
        Barang pada nota ini dikembalikan kepada {{ $retur->supplier?->nama }} dan sudah dikeluarkan
        dari gudang kami. Mohon diterbitkan nota kredit atas nilai di atas.
        @if ((int) $retur->ppn_rupiah > 0)
            PPN dihitung per baris dengan DPP 11/12 dari nilai barang, sesuai PMK 131/2024, dan
            hanya atas bagian yang sudah ditagihkan kepada kami.
        @else
            Bagian yang diretur belum pernah ditagihkan kepada kami, sehingga belum ada PPN yang
            dikreditkan dan tidak ada yang perlu dibalik.
        @endif
    </div>

    <div class="sign">
        <div>
            <div class="label">Dikembalikan oleh</div>
            <div class="line">{{ $retur->postedBy?->name ?? '' }}</div>
        </div>
        <div>
            <div class="label">Diterima kembali oleh</div>
            <div class="line">Nama jelas dan tanggal</div>
        </div>
    </div>

    <div class="foot">
        <div>{{ config('perusahaan.nama') }}</div>
        <div>{{ $retur->nomor }} · dicetak {{ now()->format('d/m/Y H:i') }}</div>
    </div>

</div>
</body>
</html>
