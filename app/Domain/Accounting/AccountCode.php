<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * The accounts the posting rules name, and the chart they come from.
 *
 * Posting rules refer to accounts by constant, never by a string typed at the
 * call site. A mistyped code would otherwise fail at run time in whichever
 * branch nobody exercised — the supplier bill with a price variance, say —
 * which is exactly the branch that matters.
 *
 * This chart is a trading company's minimum, not a template with four hundred
 * rows nobody uses. Accounts get added when a transaction needs one, because
 * that is the only way anybody ever knows what an account is for.
 */
final class AccountCode
{
    // Aset
    public const KAS = '1-1000';

    public const BANK = '1-1100';

    public const PIUTANG_USAHA = '1-1200';

    public const PERSEDIAAN = '1-1300';

    /**
     * PPN we paid our suppliers and can credit against PPN we collected —
     * but only against a faktur pajak. A bill without an NSFP is a cost, not
     * an asset, and the posting rule has to know the difference.
     */
    public const PPN_MASUKAN = '1-1400';

    // Kewajiban
    public const UTANG_USAHA = '2-1000';

    /**
     * Goods received, not yet invoiced. The goods are on the shelf and the
     * supplier has not billed for them, so we owe for them without owing
     * anybody in particular yet. This account is what makes the three-way
     * match visible in the books rather than only on a screen: it should
     * clear to nil, and whatever is left in it is a bill that never arrived.
     */
    public const UTANG_BELUM_DITAGIH = '2-1100';

    public const PPN_KELUARAN = '2-1200';

    // Modal
    public const MODAL_DISETOR = '3-1000';

    public const LABA_DITAHAN = '3-9000';

    // Pendapatan
    public const PENJUALAN = '4-1000';

    // Beban
    public const HARGA_POKOK_PENJUALAN = '5-1000';

    /**
     * The supplier billed a different price from the one the goods were
     * received at. Inventory keeps the cost it was valued at, and the
     * difference lands here instead of quietly rewriting stock value.
     */
    public const SELISIH_HARGA_PEMBELIAN = '5-2000';

    public const BEBAN_OPERASIONAL = '6-1000';

    /**
     * The whole chart, in report order.
     *
     * @return list<array{kode: string, nama: string, tipe: AccountType, dapat_diposting: bool, induk: ?string, catatan: ?string}>
     */
    public static function chart(): array
    {
        return [
            self::header('1-0000', 'ASET', AccountType::Aset),
            self::posting(self::KAS, 'Kas', AccountType::Aset, '1-0000',
                'Uang tunai di tangan. Transfer masuk tidak lewat sini — lihat Bank.'),
            self::posting(self::BANK, 'Bank', AccountType::Aset, '1-0000',
                'Rekening bank, termasuk penerimaan lewat Virtual Account.'),
            self::posting(self::PIUTANG_USAHA, 'Piutang Usaha', AccountType::Aset, '1-0000',
                'Faktur yang sudah terbit dan belum dibayar. Harus sama dengan total tagihan terbuka pelanggan.'),
            self::posting(self::PERSEDIAAN, 'Persediaan Barang Dagang', AccountType::Aset, '1-0000',
                'Nilai persediaan dengan metode rata-rata bergerak. Harus sama dengan total nilai product_costs.'),
            self::posting(self::PPN_MASUKAN, 'PPN Masukan', AccountType::Aset, '1-0000',
                'Hanya dari tagihan pemasok yang disertai faktur pajak.'),

            self::header('2-0000', 'KEWAJIBAN', AccountType::Kewajiban),
            self::posting(self::UTANG_USAHA, 'Utang Usaha', AccountType::Kewajiban, '2-0000',
                'Tagihan pemasok yang belum dibayar. Harus sama dengan total tagihan terbuka pemasok.'),
            self::posting(self::UTANG_BELUM_DITAGIH, 'Utang Belum Ditagih', AccountType::Kewajiban, '2-0000',
                'Barang sudah diterima, tagihan pemasok belum masuk. Idealnya kosong; sisanya adalah tagihan yang tidak pernah datang.'),
            self::posting(self::PPN_KELUARAN, 'PPN Keluaran', AccountType::Kewajiban, '2-0000',
                'PPN yang dipungut dari pelanggan dan terutang ke negara.'),

            self::header('3-0000', 'MODAL', AccountType::Modal),
            self::posting(self::MODAL_DISETOR, 'Modal Disetor', AccountType::Modal, '3-0000'),
            self::posting(self::LABA_DITAHAN, 'Laba Ditahan', AccountType::Modal, '3-0000',
                'Laba tahun-tahun sebelumnya. Diisi saat tutup buku tahunan.'),

            self::header('4-0000', 'PENDAPATAN', AccountType::Pendapatan),
            self::posting(self::PENJUALAN, 'Penjualan', AccountType::Pendapatan, '4-0000',
                'Nilai jual sebelum PPN. Bukan DPP — DPP adalah 11/12 harga jual dan hanya dipakai untuk menghitung PPN.'),

            self::header('5-0000', 'HARGA POKOK PENJUALAN', AccountType::Beban),
            self::posting(self::HARGA_POKOK_PENJUALAN, 'Harga Pokok Penjualan', AccountType::Beban, '5-0000',
                'Biaya barang yang dikirim, dikunci pada saat pengiriman.'),
            self::posting(self::SELISIH_HARGA_PEMBELIAN, 'Selisih Harga Pembelian', AccountType::Beban, '5-0000',
                'Selisih antara harga saat barang diterima dan harga yang ditagih pemasok.'),

            self::header('6-0000', 'BEBAN OPERASIONAL', AccountType::Beban),
            self::posting(self::BEBAN_OPERASIONAL, 'Beban Operasional', AccountType::Beban, '6-0000'),
        ];
    }

    /**
     * @return array{kode: string, nama: string, tipe: AccountType, dapat_diposting: bool, induk: ?string, catatan: ?string}
     */
    private static function header(string $kode, string $nama, AccountType $tipe): array
    {
        return [
            'kode' => $kode,
            'nama' => $nama,
            'tipe' => $tipe,
            'dapat_diposting' => false,
            'induk' => null,
            'catatan' => null,
        ];
    }

    /**
     * @return array{kode: string, nama: string, tipe: AccountType, dapat_diposting: bool, induk: ?string, catatan: ?string}
     */
    private static function posting(
        string $kode,
        string $nama,
        AccountType $tipe,
        string $induk,
        ?string $catatan = null,
    ): array {
        return [
            'kode' => $kode,
            'nama' => $nama,
            'tipe' => $tipe,
            'dapat_diposting' => true,
            'induk' => $induk,
            'catatan' => $catatan,
        ];
    }
}
