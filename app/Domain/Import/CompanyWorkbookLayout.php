<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * The customer workbook as the accounting package lays it out.
 *
 * The owner's people already have their customers in ACCURATE, and its
 * "Template Impor Pelanggan" is the file they can export and hand over
 * without retyping anything. So the importer reads that layout as it is:
 * ninety-four columns, most of them ACCURATE's own (account codes, custom
 * attributes, consignment flags) and of no meaning here. This class is the
 * translation — which of those columns say something we hold, and how each
 * becomes one of our canonical fields — and nothing else. The rules that
 * decide whether a row lands are the same rules as for the canonical CSV,
 * applied after the translation, so a customer is judged the same way
 * whichever file they arrived in.
 *
 * What is deliberately *not* translated:
 *
 * - **Saldo awal.** A receivable is a document, not a customer attribute;
 *   it goes in through Impor saldo awal piutang, where it lands as an open
 *   invoice with a number and a due date. A filled column here is noted on
 *   the row and left alone.
 * - **Default Penjual / Dipakai di Cabang.** The team in charge and the
 *   cabang are assigned by the Owner through TeamAssigner and the region
 *   context — audited, seat and region checked. A spreadsheet is not that.
 * - **Account codes.** Ours are the chart of accounts, not a per-customer
 *   setting.
 */
final class CompanyWorkbookLayout
{
    /** The first sheet's name in the template, and the only sheet read. */
    public const NAMA_SHEET = 'Template Impor Pelanggan';

    /**
     * Row 1 of the template, verbatim — the trailing space on one heading
     * included, because a template that does not match the package's own
     * export is a template nobody can round-trip.
     *
     * @var list<string>
     */
    public const JUDUL = [
        'Kategori', 'ID Pelanggan', 'Nama', 'Kontak', 'No. Telp. Bisnis', 'Handphone', 'Faximili',
        'Email', 'Website', 'Mata uang Utama', 'Saldo awal per tanggal', 'Saldo awal',
        'Mata Uang Saldo', 'Kurs Saldo (Jika Asing)', 'No. Faktur Saldo', 'Cabang Saldo',
        'Syarat Bayar Saldo', 'Penjual Saldo', 'Keterangan',
        'Karakter 1', 'Karakter 2', 'Karakter 3', 'Karakter 4', 'Karakter 5', 'Karakter 6',
        'Karakter 7', 'Karakter 8', 'Karakter 9', 'Karakter 10',
        'Angka 1', 'Angka 2', 'Angka 3', 'Angka 4', 'Angka 5', 'Angka 6', 'Angka 7', 'Angka 8',
        'Angka 9', 'Angka 10', 'Tanggal 1', 'Tanggal 2',
        'Alamat Penagihan', 'Kota', 'Provinsi', 'Negara', 'Kode Pos',
        'Alamat (Pengiriman)', 'Kota (Pengiriman)', 'Provinsi (Pengiriman)', 'Negara (Pengiriman)',
        'Kode Pos (Pengiriman)',
        'Kategori Harga', 'Kategori Diskon', 'Syarat Pembayaran',
        'Default Penjual', 'Default Penjual 2', 'Default Penjual 3', 'Default Penjual 4',
        'Default Penjual 5', 'Default Diskon (%)',
        'Tipe Wajib Pajak', 'Nomor Wajib Pajak', 'Nama Wajib Pajak', 'ID TKU', 'Tipe Transaksi',
        'Detail Transaksi', 'NPPKP', 'Alamat (Pajak)', 'Kota (Pajak)', 'Provinsi (Pajak)',
        'Negara (Pajak)', 'Kode Pos (Pajak)',
        'Dipakai di Cabang', 'Default Deskripsi', 'Konsinyasi',
        'Akun Piutang', 'Akun Uang Muka', 'Akun Penjualan', 'Akun Beban Pokok Penjualan ',
        'Akun Retur Penjualan', 'Akun Diskon Barang', 'Akun Diskon Penjualan',
        'Default Total Faktur sudah termasuk Pajak', 'Nilai Umur Piutang (hari)',
        'Jumlah Limit Piutang', 'Limit Gabung ke Pelanggan', 'Catatan', 'Nomor VA', 'Non Aktif',
        'Gudang Default',
    ];

    /**
     * What the person reading the screen needs: which columns this system
     * reads, and what each becomes. Every other column is accepted and
     * ignored. Keyed by the template's own heading.
     *
     * @return array<string, string>
     */
    public static function keterangan(): array
    {
        return [
            'ID Pelanggan' => 'Wajib. Kode pelanggan, unik. Kode yang sudah ada akan memperbarui data itu — sistem ini tidak membuat kode otomatis.',
            'Nama' => 'Wajib. Nama usaha.',
            'Kategori' => 'Wajib. Bengkel, Toko Sparepart, atau Distributor — jenis usaha pelanggan, bukan kategori ACCURATE (Umum/Member/Corporate).',
            'Kontak' => 'Nama orang yang dihubungi.',
            'No. Telp. Bisnis' => 'Nomor telepon. Kosong → dipakai Handphone.',
            'Handphone' => 'Dipakai bila No. Telp. Bisnis kosong.',
            'Email' => 'Alamat email — juga dipakai di file XML faktur pajak Coretax.',
            'Alamat (Pengiriman)' => 'Alamat kirim barang, digabung dengan Kota, Provinsi dan Kode Pos (Pengiriman). Kosong → dipakai Alamat Penagihan.',
            'Kota (Pengiriman)' => 'Kota pelanggan. Kosong → dipakai Kota (penagihan).',
            'Tipe Wajib Pajak' => 'NPWP atau kosong. NIK/PASSPORT/OTHER dicatat tapi nomornya tidak disimpan — faktur pajak di sini memakai NPWP.',
            'Nomor Wajib Pajak' => 'NPWP, 15 atau 16 digit. Kosongkan bila belum ada.',
            'Nama Wajib Pajak' => 'Nama sesuai NPWP.',
            'ID TKU' => '22 digit NITKU bila pelanggan membeli lewat cabang terdaftar. Kosong = kantor pusat (NPWP + 000000).',
            'Alamat (Pajak)' => 'Alamat sesuai NPWP, digabung dengan Kota, Provinsi dan Kode Pos (Pajak).',
            'Kategori Harga' => 'Nama tier harga persis seperti di layar Tier harga. Kosong berarti harga dasar.',
            'Syarat Pembayaran' => 'Jumlah hari jatuh tempo, mis. 30, "Net 30" atau "30 hari". Kosong → dipakai Nilai Umur Piutang (hari).',
            'Nilai Umur Piutang (hari)' => 'Dipakai bila Syarat Pembayaran kosong.',
            'Jumlah Limit Piutang' => 'Limit kredit dalam rupiah. Hanya boleh terisi bila yang mengimpor Keuangan atau Pemilik.',
            'Catatan' => 'Catatan bebas.',
            'Non Aktif' => 'YA/YES → ditangguhkan. Kosong/TIDAK → pelanggan baru masuk sebagai menunggu persetujuan; pelanggan lama tidak berubah statusnya.',
            'Saldo awal' => 'Tidak dibaca. Piutang awal masuk lewat Impor saldo awal piutang, sebagai faktur bernomor.',
        ];
    }

    /**
     * Is this the workbook layout? Recognised by two headings that only it
     * has; the canonical CSV says KODE and NAMA in capitals.
     *
     * @param  list<string>  $header
     */
    public static function kenali(array $header): bool
    {
        $rapi = array_map(self::rapikan(...), $header);

        return in_array('id pelanggan', $rapi, true) && in_array('nama', $rapi, true);
    }

    /**
     * Heading → position, keyed by the tidied heading so a stray space or a
     * different case in row 1 does not lose a column.
     *
     * @param  list<string>  $header
     * @return array<string, int>
     */
    public static function posisi(array $header): array
    {
        $map = [];

        foreach ($header as $i => $judul) {
            $judul = self::rapikan($judul);

            if ($judul !== '' && ! isset($map[$judul])) {
                $map[$judul] = $i;
            }
        }

        return $map;
    }

    /**
     * One workbook row as canonical cells, plus the notes the translation
     * itself produced.
     *
     * @param  array<string, int>  $posisi  from `posisi()`
     * @param  list<string>  $cells  the raw row
     * @return array{0: array<string, string>, 1: list<string>}
     */
    public static function keCanonical(array $posisi, array $cells): array
    {
        $baca = fn (string $judul): string => trim((string) ($cells[$posisi[self::rapikan($judul)] ?? -1] ?? ''));
        $catatan = [];

        $tipePajak = strtoupper($baca('Tipe Wajib Pajak'));
        $nomorPajak = $baca('Nomor Wajib Pajak');
        $npwp = '';

        if ($nomorPajak !== '') {
            if ($tipePajak === '' || $tipePajak === 'NPWP') {
                $npwp = $nomorPajak;
            } else {
                $catatan[] = "Tipe Wajib Pajak {$tipePajak} — nomornya tidak disimpan, faktur pajak memakai NPWP";
            }
        }

        $saldoAwal = $baca('Saldo awal');

        if ($saldoAwal !== '' && preg_replace('/[^\d]/', '', $saldoAwal) !== '' && (int) preg_replace('/[^\d]/', '', $saldoAwal) > 0) {
            $catatan[] = "Saldo awal {$saldoAwal} diabaikan — masukkan lewat Impor saldo awal piutang";
        }

        $tempo = self::hari($baca('Syarat Pembayaran'));

        if ($tempo === '') {
            $tempo = self::hari($baca('Nilai Umur Piutang (hari)'));
        }

        $nonAktif = strtoupper($baca('Non Aktif'));

        return [[
            'KODE' => $baca('ID Pelanggan'),
            'NAMA' => $baca('Nama'),
            'JENIS_USAHA' => self::jenisUsaha($baca('Kategori')),
            'NAMA_KONTAK' => $baca('Kontak'),
            'TELEPON' => $baca('No. Telp. Bisnis') ?: $baca('Handphone'),
            'EMAIL' => $baca('Email'),
            'KOTA' => $baca('Kota (Pengiriman)') ?: $baca('Kota'),
            'ALAMAT_KIRIM' => self::alamat($baca, 'Alamat (Pengiriman)', 'Kota (Pengiriman)', 'Provinsi (Pengiriman)', 'Kode Pos (Pengiriman)')
                ?: self::alamat($baca, 'Alamat Penagihan', 'Kota', 'Provinsi', 'Kode Pos'),
            'NPWP' => $npwp,
            'ID_TKU' => $baca('ID TKU'),
            'NAMA_WAJIB_PAJAK' => $baca('Nama Wajib Pajak'),
            'ALAMAT_PAJAK' => self::alamat($baca, 'Alamat (Pajak)', 'Kota (Pajak)', 'Provinsi (Pajak)', 'Kode Pos (Pajak)'),
            'TIER' => $baca('Kategori Harga'),
            'LIMIT_KREDIT' => $baca('Jumlah Limit Piutang'),
            'TEMPO_HARI' => $tempo,
            'STATUS' => in_array($nonAktif, ['YA', 'YES', 'Y', 'TRUE', '1'], true) ? 'ditangguhkan' : '',
            'CATATAN' => $baca('Catatan'),
        ], $catatan];
    }

    /**
     * The template's headings, in the canonical CSV's terms — so a held row
     * names the column the person is looking at in their spreadsheet.
     *
     * @return array<string, string>
     */
    public static function label(): array
    {
        return [
            'KODE' => 'ID Pelanggan',
            'NAMA' => 'Nama',
            'JENIS_USAHA' => 'Kategori',
            'ID_TKU' => 'ID TKU',
            'TIER' => 'Kategori Harga',
            'LIMIT_KREDIT' => 'Jumlah Limit Piutang',
            'TEMPO_HARI' => 'Syarat Pembayaran',
            'STATUS' => 'Non Aktif',
        ];
    }

    /**
     * Two example customers in this layout — the same two the canonical
     * template shows, so the two files teach the same thing.
     *
     * @return list<list<string>>
     */
    public static function contoh(): array
    {
        $rows = [];

        foreach (CompanyColumns::contoh() as $contoh) {
            $canonical = array_combine(CompanyColumns::COLUMNS, $contoh);
            $row = array_fill(0, count(self::JUDUL), '');
            $set = function (string $judul, string $nilai) use (&$row): void {
                $row[array_search($judul, self::JUDUL, true)] = $nilai;
            };

            $set('Kategori', match ($canonical['JENIS_USAHA']) {
                'bengkel' => 'Bengkel', 'toko_sparepart' => 'Toko Sparepart', default => 'Distributor',
            });
            $set('ID Pelanggan', $canonical['KODE']);
            $set('Nama', $canonical['NAMA']);
            $set('Kontak', $canonical['NAMA_KONTAK']);
            $set('Handphone', $canonical['TELEPON']);
            $set('Email', $canonical['EMAIL']);
            $set('Mata uang Utama', 'IDR');
            $set('Alamat (Pengiriman)', $canonical['ALAMAT_KIRIM']);
            $set('Kota (Pengiriman)', $canonical['KOTA']);
            $set('Negara (Pengiriman)', 'Indonesia');
            $set('Kategori Harga', $canonical['TIER']);
            $set('Syarat Pembayaran', $canonical['TEMPO_HARI']);
            $set('Tipe Wajib Pajak', $canonical['NPWP'] === '' ? '' : 'NPWP');
            $set('Nomor Wajib Pajak', $canonical['NPWP']);
            $set('Nama Wajib Pajak', $canonical['NAMA_WAJIB_PAJAK']);
            $set('ID TKU', $canonical['ID_TKU'] ?? '');
            $set('Tipe Transaksi', $canonical['NPWP'] === '' ? '' : 'CTAS_INVOICE');
            $set('Detail Transaksi', $canonical['NPWP'] === '' ? '' : 'CTAS_DPP_NILAI_LAIN');
            $set('Alamat (Pajak)', $canonical['ALAMAT_PAJAK']);
            $set('Jumlah Limit Piutang', $canonical['LIMIT_KREDIT']);
            $set('Catatan', $canonical['CATATAN']);
            $set('Non Aktif', 'TIDAK');

            $rows[] = $row;
        }

        return $rows;
    }

    /** The heading as a lookup key: lower case, one space, no edges. */
    public static function rapikan(string $judul): string
    {
        return strtolower(trim(preg_replace('/\s+/u', ' ', $judul) ?? $judul));
    }

    /**
     * ACCURATE's free-text Kategori as our jenis_usaha.
     *
     * "Toko Sparepart", "toko sparepart" and "TOKO_SPAREPART" are one thing;
     * "Umum" is not any of ours and goes through as typed, for the importer
     * to hold the row with the reason naming it.
     */
    private static function jenisUsaha(string $kategori): string
    {
        $rapi = strtolower(trim(preg_replace('/[\s_\-]+/u', '_', $kategori) ?? $kategori));

        return match ($rapi) {
            'toko', 'toko_sparepart', 'toko_spare_part', 'sparepart', 'toko_onderdil' => 'toko_sparepart',
            'bengkel' => 'bengkel',
            'distributor', 'distribusi', 'grosir' => 'distributor',
            default => $rapi,
        };
    }

    /**
     * Street, city, province and postcode as one line, skipping the blanks.
     *
     * @param  callable(string): string  $baca
     */
    private static function alamat(callable $baca, string ...$judul): string
    {
        $bagian = array_filter(array_map($baca, $judul), fn (string $b) => $b !== '');

        return implode(', ', $bagian);
    }

    /**
     * Days out of a payment term however it was written: `30`, `Net 30`,
     * `30 hari`, `N/30`. Anything without a number is nothing.
     */
    private static function hari(string $syarat): string
    {
        return preg_match('/(\d+)/', $syarat, $m) === 1 ? $m[1] : '';
    }
}
