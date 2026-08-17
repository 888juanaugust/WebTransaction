<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * The e-Faktur import CSV: three row types, one file.
 *
 * Each faktur is written as an `FK` header row, one `LT` row carrying the
 * buyer's details, and an `OF` row per item. The file opens with three
 * definition rows naming the columns of each type — that is part of the
 * format, not a nicety, and the importer reads them.
 *
 * **Verify the column lists below against a template downloaded from the tax
 * office before the first real filing.** They are declared as data, one array
 * per row type, so that check is a line-by-line diff rather than a reading of
 * code. See FakturWriter for why this is not settled.
 *
 * Two things a reviewer should look at hardest:
 *
 * - **`NOMOR_FAKTUR` is written empty.** The serial is not ours to choose; the
 *   tax office assigns one from our allocated range and hands it back, and
 *   that is what NsfpRecorder writes onto the invoice. Filling this in with
 *   our own invoice number is a common and expensive mistake.
 * - **`DPP` under transaction code 04 is the Nilai Lain**, which is 11/12 of
 *   the selling price, *not* harga total less discount. Under code 01 those
 *   two are the same number and the distinction is invisible; under 04 they
 *   differ by a twelfth, and this writer emits the stored DPP snapshot rather
 *   than recomputing from the line. Worth confirming with the accountant that
 *   the importer expects it that way round.
 */
class EFakturCsvWriter implements FakturWriter
{
    /**
     * Faktur header. One per invoice.
     *
     * @var list<string>
     */
    public const COLUMNS_FK = [
        'FK', 'KD_JENIS_TRANSAKSI', 'FG_PENGGANTI', 'NOMOR_FAKTUR', 'MASA_PAJAK',
        'TAHUN_PAJAK', 'TANGGAL_FAKTUR', 'NPWP', 'NAMA', 'ALAMAT_LENGKAP',
        'JUMLAH_DPP', 'JUMLAH_PPN', 'JUMLAH_PPNBM', 'ID_KETERANGAN_TAMBAHAN',
        'FG_UANG_MUKA', 'UANG_MUKA_DPP', 'UANG_MUKA_PPN', 'UANG_MUKA_PPNBM',
        'REFERENSI', 'KODE_DOKUMEN_PENDUKUNG',
    ];

    /**
     * Lawan transaksi — the buyer.
     *
     * The address is one field for us and eleven for the importer. We hold a
     * single free-text `alamat_pajak` because that is what a person types and
     * what the printed faktur shows; splitting it into street, block, RT, RW,
     * kelurahan and the rest would mean guessing at boundaries in text nobody
     * entered structurally. The whole address goes into JALAN and the
     * remaining fields are left empty, which the importer accepts.
     *
     * @var list<string>
     */
    public const COLUMNS_LT = [
        'LT', 'NPWP', 'NAMA', 'JALAN', 'BLOK', 'NOMOR', 'RT', 'RW', 'KECAMATAN',
        'KELURAHAN', 'KABUPATEN', 'PROPINSI', 'KODE_POS', 'NOMOR_TELEPON',
    ];

    /**
     * Objek faktur — one per item.
     *
     * @var list<string>
     */
    public const COLUMNS_OF = [
        'OF', 'KODE_OBJEK', 'NAMA', 'HARGA_SATUAN', 'JUMLAH_BARANG', 'HARGA_TOTAL',
        'DISKON', 'DPP', 'PPN', 'TARIF_PPNBM', 'PPNBM',
    ];

    /** 0 = an original faktur. 1 would be a replacement for a corrected one. */
    private const FG_PENGGANTI_ASLI = '0';

    public function format(): string
    {
        return 'efaktur_csv';
    }

    public function extension(): string
    {
        return 'csv';
    }

    /**
     * @param  list<FakturRecord>  $fakturs
     */
    public function write(array $fakturs): string
    {
        /*
         * The three definition rows go out bare. They are field names, not
         * values, and the importer matches them literally — quoting them
         * makes the file unreadable to it.
         */
        $lines = [
            implode(',', self::COLUMNS_FK),
            implode(',', self::COLUMNS_LT),
            implode(',', self::COLUMNS_OF),
        ];

        foreach ($fakturs as $faktur) {
            $lines[] = $this->toCsvLine($this->headerRow($faktur));
            $lines[] = $this->toCsvLine($this->buyerRow($faktur));

            foreach ($faktur->lines as $line) {
                $lines[] = $this->toCsvLine($this->itemRow($line));
            }
        }

        // CRLF throughout, which is what the importer expects on Windows and
        // what it was written against.
        return implode("\r\n", $lines)."\r\n";
    }

    /** @return list<string> */
    private function headerRow(FakturRecord $faktur): array
    {
        return [
            'FK',
            $faktur->kodeTransaksi,
            self::FG_PENGGANTI_ASLI,
            // Left empty on purpose. See the class comment.
            '',
            (string) $faktur->masaPajak,
            (string) $faktur->tahunPajak,
            $faktur->tanggalFaktur->format('d/m/Y'),
            $this->digitsOf($faktur->npwp),
            $faktur->namaWajibPajak,
            $this->flatten($faktur->alamatPajak),
            (string) $faktur->dppRupiah,
            (string) $faktur->ppnRupiah,
            // PPnBM: luxury goods tax. Never us — spare parts are not luxury
            // goods — but the column is not optional.
            '0',
            '',
            '0',
            '0',
            '0',
            '0',
            $faktur->referensi,
            '',
        ];
    }

    /** @return list<string> */
    private function buyerRow(FakturRecord $faktur): array
    {
        return [
            'LT',
            $this->digitsOf($faktur->npwp),
            $faktur->namaWajibPajak,
            $this->flatten($faktur->alamatPajak),
            '', '', '', '', '', '', '', '', '', '',
        ];
    }

    /** @return list<string> */
    private function itemRow(FakturLine $line): array
    {
        return [
            'OF',
            $line->kode,
            $this->flatten($line->nama),
            (string) $line->hargaSatuanRupiah,
            (string) $line->jumlahBarang,
            (string) $line->hargaTotalRupiah,
            (string) $line->diskonRupiah,
            (string) $line->dppRupiah,
            (string) $line->ppnRupiah,
            '0',
            '0',
        ];
    }

    /**
     * NPWP as digits only.
     *
     * People type it with the dots and dashes the card shows, and both the
     * 15-digit and the 16-digit forms are in circulation since the 2024
     * change. Stripping punctuation leaves whichever length the customer
     * actually has, which is the right thing to send — padding or truncating
     * to a preferred length would be inventing somebody's tax number.
     */
    private function digitsOf(string $npwp): string
    {
        return preg_replace('/\D+/', '', $npwp) ?? '';
    }

    /**
     * Collapse a value so it cannot break the row.
     *
     * Addresses are a textarea and descriptions come from a supplier
     * spreadsheet; both contain newlines. A newline mid-field ends the record
     * early and every column after it lands in the wrong place — the importer
     * either rejects the file or, worse, accepts a shifted one.
     */
    private function flatten(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * One CSV line.
     *
     * Comma-separated with quotes where needed, which is what the importer
     * documentation describes. Quoting is applied per field rather than to
     * everything, because some versions of the importer treat a quoted numeric
     * as text — so numbers go out bare and only text that needs protecting is
     * wrapped.
     *
     * The row-type marker in the first field goes out bare, like the
     * definition rows above it. The importer matches the row type literally at
     * the start of the line, and a quoted `"FK"` is not the same token as the
     * `FK` it was told to look for.
     *
     * @param  list<string>  $fields
     */
    private function toCsvLine(array $fields): string
    {
        return implode(',', array_map(function (string $field, int $i): string {
            if ($i === 0 || $field === '' || preg_match('/^-?\d+$/', $field) === 1) {
                return $field;
            }

            return '"'.str_replace('"', '""', $field).'"';
        }, $fields, array_keys($fields)));
    }
}
