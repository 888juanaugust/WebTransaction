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

    /**
     * Receivables we hold a bilyet giro against, not yet cleared.
     *
     * Separate from Piutang Usaha because it is a different risk, not a
     * different customer. A giro is a signed instrument with a date on it —
     * better evidence than an invoice — and it can still bounce, which an
     * invoice cannot do. Folding the two together would hide both halves of
     * that: how much of the debt is documented, and how much of it is one
     * bank rejection away from coming straight back.
     *
     * **A giro does not free the customer's credit limit.** The books move the
     * balance here; the credit check goes on counting it. See
     * OutstandingReceivables for why those are two different figures.
     */
    public const PIUTANG_GIRO = '1-1250';

    public const PERSEDIAAN = '1-1300';

    /**
     * Freight, duty and handling that belong in the cost of goods but have not
     * been spread over them yet.
     *
     * A charge like this arrives on its own invoice, days or weeks after the
     * goods it belongs to. Between the two, it has to sit somewhere honest:
     * booking it to expense means the stock is understated and every sale from
     * that shipment shows too much margin, and holding it off the books until
     * somebody allocates it means the supplier is owed money the ledger does
     * not admit to.
     *
     * So it lands here, and an allocation drains it into Persediaan and HPP.
     * **A balance in this account is a work item, not a figure** — it is the
     * charges nobody has spread yet, and it should be empty at month end.
     */
    public const BIAYA_BELUM_DIALOKASIKAN = '1-1350';

    /**
     * PPN we paid our suppliers and can credit against PPN we collected —
     * but only against a faktur pajak. A bill without an NSFP is a cost, not
     * an asset, and the posting rule has to know the difference.
     */
    public const PPN_MASUKAN = '1-1400';

    // Kewajiban
    public const UTANG_USAHA = '2-1000';

    /**
     * Bilyet giro we have issued that has not been cashed yet.
     *
     * The mirror of Piutang Giro, and it matters for the opposite reason: the
     * money is committed and dated, so it is not available to spend even
     * though it is still in the bank account. A supplier holding our giro for
     * the 20th is a claim on the 20th's cash, and a payables figure that does
     * not separate it makes next month's cash look better than it is.
     */
    public const UTANG_GIRO = '2-1050';

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

    /**
     * Income that is not selling parts: bank interest, mostly.
     *
     * Kept out of Penjualan on purpose. Penjualan is the top line every margin
     * figure in the system divides into, and a few hundred thousand of bank
     * interest folded into it would flatter the gross margin on goods that
     * were never sold.
     */
    public const PENDAPATAN_LAIN = '4-9000';

    // Beban
    public const HARGA_POKOK_PENJUALAN = '5-1000';

    /**
     * The supplier billed a different price from the one the goods were
     * received at. Inventory keeps the cost it was valued at, and the
     * difference lands here instead of quietly rewriting stock value.
     */
    public const SELISIH_HARGA_PEMBELIAN = '5-2000';

    /**
     * What a stock count found missing — or found extra.
     *
     * Shrinkage is a cost of trading rather than an overhead: it moves with
     * how much stock is handled, which is why it hangs under the HPP header
     * and lands above gross profit. A surplus credits the same account, and a
     * surplus is not good news — it means the count and the ledger disagree in
     * the other direction, and the cause is usually a movement never recorded.
     */
    public const SELISIH_PERSEDIAAN = '5-3000';

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
            self::posting(self::PIUTANG_GIRO, 'Piutang Giro', AccountType::Aset, '1-0000',
                'Bilyet giro dari pelanggan yang belum cair. Tetap dihitung sebagai eksposur kredit sampai cair.'),
            self::posting(self::PERSEDIAAN, 'Persediaan Barang Dagang', AccountType::Aset, '1-0000',
                'Nilai persediaan dengan metode rata-rata bergerak. Harus sama dengan total nilai product_costs.'),
            self::posting(self::BIAYA_BELUM_DIALOKASIKAN, 'Biaya Perolehan Belum Dialokasikan', AccountType::Aset, '1-0000',
                'Ongkos angkut, bea masuk dan sejenisnya yang belum dibebankan ke barangnya. Idealnya kosong di akhir bulan.'),
            self::posting(self::PPN_MASUKAN, 'PPN Masukan', AccountType::Aset, '1-0000',
                'Hanya dari tagihan pemasok yang disertai faktur pajak.'),

            self::header('2-0000', 'KEWAJIBAN', AccountType::Kewajiban),
            self::posting(self::UTANG_USAHA, 'Utang Usaha', AccountType::Kewajiban, '2-0000',
                'Tagihan pemasok yang belum dibayar. Harus sama dengan total tagihan terbuka pemasok.'),
            self::posting(self::UTANG_GIRO, 'Utang Giro', AccountType::Kewajiban, '2-0000',
                'Bilyet giro yang kita terbitkan dan belum dicairkan pemasok. Uangnya masih di bank tapi sudah terikat tanggal.'),
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
            self::posting(self::PENDAPATAN_LAIN, 'Pendapatan Lain-lain', AccountType::Pendapatan, '4-0000',
                'Bunga bank dan penerimaan lain di luar penjualan barang. Sengaja dipisah supaya tidak masuk hitungan margin.'),

            self::header('5-0000', 'HARGA POKOK PENJUALAN', AccountType::Beban),
            self::posting(self::HARGA_POKOK_PENJUALAN, 'Harga Pokok Penjualan', AccountType::Beban, '5-0000',
                'Biaya barang yang dikirim, dikunci pada saat pengiriman.'),
            self::posting(self::SELISIH_HARGA_PEMBELIAN, 'Selisih Harga Pembelian', AccountType::Beban, '5-0000',
                'Selisih antara harga saat barang diterima dan harga yang ditagih pemasok.'),
            self::posting(self::SELISIH_PERSEDIAAN, 'Selisih Persediaan', AccountType::Beban, '5-0000',
                'Selisih hasil stok opname terhadap catatan. Idealnya kecil; kalau besar, cari sebabnya.'),

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
