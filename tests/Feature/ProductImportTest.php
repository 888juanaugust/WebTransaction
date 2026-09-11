<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Catalogue\Golongan;
use App\Domain\Import\CsvTemplate;
use App\Domain\Import\ProductImporter;
use App\Domain\Import\ProductImportRow;
use App\Domain\Import\TemplateKind;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalogue arrives in one file, and the file is read before it is
 * believed.
 *
 * This is now the only way a SKU is created — `ProductResource::canCreate()`
 * is false and the create route is gone — so everything the one-at-a-time
 * form used to guarantee has to be guaranteed here instead, on a file
 * somebody exported from a supplier's workbook and edited in Excel.
 */
class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    private function keeper(): User
    {
        return User::factory()->create(['role' => Role::Warehouse->value, 'is_active' => true]);
    }

    /** @param list<string> $rows */
    private function csv(array $rows, ?string $header = null): string
    {
        $header ??= 'KODE,MERK,KATEGORI,TIPE_PRODUK,MOBIL,PART_NUMBER,DESCRIPTION,QTY_PER_CTN,SATUAN_DASAR,AKTIF,CATATAN';

        return $header."\n".implode("\n", $rows)."\n";
    }

    private function importer(): ProductImporter
    {
        return app(ProductImporter::class);
    }

    // --- one file, the whole catalogue -------------------------------------

    public function test_one_file_creates_every_item_in_it(): void
    {
        $keeper = $this->keeper();

        $rows = [];

        for ($i = 1; $i <= 50; $i++) {
            $kode = sprintf('BULK-%03d', $i);
            $rows[] = "{$kode},YUHOLI,HYDRAULIC PART,Master rem,Avanza,MC-{$i},Barang {$i},10,PCS,Y,";
        }

        $hasil = $this->importer()->import($this->csv($rows), $keeper, sumber: 'katalog.csv');

        // The point of the feature: one CSV, the whole list, one press.
        $this->assertSame(['baru' => 50, 'diperbarui' => 0, 'tertahan' => 0], $hasil);
        $this->assertSame(50, Product::query()->where('kode', 'like', 'BULK-%')->count());

        $first = Product::query()->whereKey('BULK-001')->sole();
        $this->assertSame('YUHOLI', $first->merk);
        $this->assertSame('HYDRAULIC PART', $first->kategori);
        $this->assertSame(10, $first->qty_per_ctn);
        $this->assertSame('PCS', $first->satuan_dasar);
        $this->assertTrue($first->aktif);
    }

    public function test_a_code_that_already_exists_updates_rather_than_duplicating(): void
    {
        $keeper = $this->keeper();
        Product::factory()->create(['kode' => 'YH-1001', 'description' => 'Nama lama', 'qty_per_ctn' => 4]);

        $hasil = $this->importer()->import($this->csv([
            'YH-1001,YUHOLI,HYDRAULIC PART,Master rem,Avanza,MC-1001,Nama baru,10,PCS,Y,',
        ]), $keeper);

        $this->assertSame(['baru' => 0, 'diperbarui' => 1, 'tertahan' => 0], $hasil);
        $this->assertSame(1, Product::query()->whereKey('YH-1001')->count());

        $product = Product::query()->whereKey('YH-1001')->sole();
        $this->assertSame('Nama baru', $product->description);
        $this->assertSame(10, $product->qty_per_ctn);
    }

    public function test_the_preview_writes_nothing(): void
    {
        $keeper = $this->keeper();

        $rows = $this->importer()->preview($this->csv([
            'PRV-1,YUHOLI,HYDRAULIC PART,Master rem,Avanza,MC-1,Barang,10,PCS,Y,',
        ]), $keeper);

        $this->assertCount(1, $rows);
        $this->assertSame(ProductImportRow::BARU, $rows[0]->status);

        // A bulk tool that writes as it reads leaves half a catalogue and no
        // way to tell which half.
        $this->assertSame(0, Product::query()->count());
    }

    public function test_the_import_is_audited_with_its_file_name(): void
    {
        $keeper = $this->keeper();

        $this->importer()->import($this->csv([
            'AUD-1,YUHOLI,HYDRAULIC PART,Master rem,Avanza,MC-1,Barang,10,PCS,Y,',
        ]), $keeper, sumber: 'katalog-september.csv');

        $log = AuditLog::query()->where('action', 'products_imported')->sole();

        $this->assertSame('katalog-september.csv', $log->new_value['berkas']);
        $this->assertSame(1, $log->new_value['baru']);
    }

    // --- what gets held back ------------------------------------------------

    public function test_a_row_the_importer_holds_back_is_not_written(): void
    {
        $keeper = $this->keeper();

        $hasil = $this->importer()->import($this->csv([
            'OK-1,YUHOLI,HYDRAULIC PART,Master rem,Avanza,MC-1,Bagus,10,PCS,Y,',
            'BAD-1,TIDAKADA,HYDRAULIC PART,Master rem,Avanza,MC-2,Merk ngawur,10,PCS,Y,',
        ]), $keeper);

        $this->assertSame(['baru' => 1, 'diperbarui' => 0, 'tertahan' => 1], $hasil);

        // The good row still lands: one bad line does not cost you the file.
        $this->assertTrue(Product::query()->whereKey('OK-1')->exists());
        $this->assertFalse(Product::query()->whereKey('BAD-1')->exists());
    }

    public function test_the_reasons_a_row_is_held_are_specific_enough_to_fix(): void
    {
        $keeper = $this->keeper();

        $rows = $this->importer()->preview($this->csv([
            ',YUHOLI,HYDRAULIC PART,T,M,P,Tanpa kode,10,PCS,Y,',
            'A-1,NGAWUR,HYDRAULIC PART,T,M,P,Merk salah,10,PCS,Y,',
            'A-2,YUHOLI,BUKAN KATEGORI,T,M,P,Kategori salah,10,PCS,Y,',
            'A-3,YUHOLI,HYDRAULIC PART,T,M,P,Satuan salah,10,DUS,Y,',
            'A-4,YUHOLI,HYDRAULIC PART,T,M,P,Qty bukan angka,sepuluh,PCS,Y,',
            'A-5,YUHOLI,HYDRAULIC PART,T,M,P,Aktif ngawur,10,PCS,MUNGKIN,',
            'A-6/A-7,YUHOLI,HYDRAULIC PART,T,M,P,Dua kode satu sel,10,PCS,Y,',
        ]), $keeper);

        $alasan = array_map(fn (ProductImportRow $r) => implode(' | ', $r->alasan), $rows);

        $this->assertStringContainsString('KODE kosong', $alasan[0]);
        $this->assertStringContainsString("MERK 'NGAWUR' tidak dikenal", $alasan[1]);
        $this->assertStringContainsString("KATEGORI 'BUKAN KATEGORI' tidak dikenal", $alasan[2]);
        $this->assertStringContainsString("SATUAN_DASAR 'DUS' bukan PCS atau SET", $alasan[3]);
        $this->assertStringContainsString('QTY_PER_CTN bukan angka', $alasan[4]);
        $this->assertStringContainsString("AKTIF 'MUNGKIN' tidak dikenal", $alasan[5]);
        // The supplier workbook's habit of putting two SKUs in one cell.
        $this->assertStringContainsString('berisi lebih dari satu kode', $alasan[6]);

        foreach ($rows as $row) {
            $this->assertTrue($row->tertahan(), "baris {$row->baris} seharusnya tertahan");
        }
    }

    public function test_the_same_code_twice_in_one_file_is_caught(): void
    {
        $keeper = $this->keeper();

        $rows = $this->importer()->preview($this->csv([
            'DUP-1,YUHOLI,HYDRAULIC PART,T,M,P,Pertama,10,PCS,Y,',
            'DUP-1,YUHOLI,HYDRAULIC PART,T,M,P,Kedua,10,PCS,Y,',
        ]), $keeper);

        // The first is fine; the second would silently overwrite it inside one
        // import, which is a file somebody needs to fix rather than a merge.
        $this->assertSame(ProductImportRow::BARU, $rows[0]->status);
        $this->assertTrue($rows[1]->tertahan());
        $this->assertStringContainsString('muncul dua kali', implode(' ', $rows[1]->alasan));
    }

    // --- golongan: impor, titip impor, lokal -------------------------------

    public function test_golongan_is_read_from_the_file_the_way_people_type_it(): void
    {
        $keeper = $this->keeper();
        $header = 'KODE,MERK,KATEGORI,GOLONGAN,TIPE_PRODUK,MOBIL,PART_NUMBER,DESCRIPTION,QTY_PER_CTN,SATUAN_DASAR,AKTIF,CATATAN';

        // Three spellings people actually use for the middle one, and the
        // other two in whatever case the spreadsheet left them.
        $this->importer()->import($this->csv([
            'GOL-1,YUHOLI,HYDRAULIC PART,IMPOR,Master rem,Avanza,MC-1,Barang 1,10,PCS,Y,',
            'GOL-2,YUHOLI,HYDRAULIC PART,Titip Impor,Master rem,Avanza,MC-2,Barang 2,10,PCS,Y,',
            'GOL-3,YUHOLI,HYDRAULIC PART,titip_impor,Master rem,Avanza,MC-3,Barang 3,10,PCS,Y,',
            'GOL-4,YUHOLI,HYDRAULIC PART,lokal,Master rem,Avanza,MC-4,Barang 4,10,PCS,Y,',
            'GOL-5,YUHOLI,HYDRAULIC PART,,Master rem,Avanza,MC-5,Barang 5,10,PCS,Y,',
        ], $header), $keeper);

        $this->assertSame('impor', Product::query()->whereKey('GOL-1')->sole()->golongan);
        $this->assertSame('titip_impor', Product::query()->whereKey('GOL-2')->sole()->golongan);
        $this->assertSame('titip_impor', Product::query()->whereKey('GOL-3')->sole()->golongan);
        $this->assertSame('lokal', Product::query()->whereKey('GOL-4')->sole()->golongan);

        // Blank is a real answer — not yet classified — and it reads as such.
        $belum = Product::query()->whereKey('GOL-5')->sole();
        $this->assertNull($belum->golongan);
        $this->assertSame(Golongan::BELUM, $belum->golonganLabel());
    }

    public function test_a_golongan_nobody_recognises_holds_the_row_and_names_the_choices(): void
    {
        $keeper = $this->keeper();
        $header = 'KODE,MERK,KATEGORI,GOLONGAN,TIPE_PRODUK,MOBIL,PART_NUMBER,DESCRIPTION,QTY_PER_CTN,SATUAN_DASAR,AKTIF,CATATAN';

        // "IMPORT" is somebody meaning impor. Held rather than guessed at,
        // and the reason lists the three words that would have worked.
        $rows = $this->importer()->preview($this->csv([
            'GOL-X,YUHOLI,HYDRAULIC PART,IMPORT,Master rem,Avanza,MC-1,Barang 1,10,PCS,Y,',
        ], $header), $keeper);

        $this->assertTrue($rows[0]->tertahan());
        $alasan = implode(' ', $rows[0]->alasan);
        $this->assertStringContainsString("GOLONGAN 'IMPORT' tidak dikenal", $alasan);
        $this->assertStringContainsString('IMPOR, TITIP IMPOR, LOKAL', $alasan);
    }

    public function test_a_file_without_the_golongan_column_still_imports(): void
    {
        // The column is new; every file made before it exists lacks it, and
        // those files must not start failing. Default header, no GOLONGAN.
        $keeper = $this->keeper();

        $hasil = $this->importer()->import($this->csv([
            'LAMA-1,YUHOLI,HYDRAULIC PART,Master rem,Avanza,MC-1,Barang 1,10,PCS,Y,',
        ]), $keeper);

        $this->assertSame(['baru' => 1, 'diperbarui' => 0, 'tertahan' => 0], $hasil);
        $this->assertNull(Product::query()->whereKey('LAMA-1')->sole()->golongan);
    }

    public function test_a_blank_carton_size_defaults_to_one_and_says_so(): void
    {
        $keeper = $this->keeper();

        $rows = $this->importer()->preview($this->csv([
            'QTY-1,YUHOLI,HYDRAULIC PART,T,M,P,Tanpa isi dus,,PCS,Y,',
        ]), $keeper);

        // CLAUDE.md's rule for the supplier workbook's ~724 blanks: import
        // anyway, default to 1, annotate — a note, not a blocker.
        $this->assertFalse($rows[0]->tertahan());
        $this->assertSame(1, $rows[0]->nilai['qty_per_ctn']);
        $this->assertStringContainsString('dianggap 1', implode(' ', $rows[0]->catatan));
    }

    // --- the things this import must not be able to do ----------------------

    public function test_a_file_carrying_prices_is_refused_rather_than_ignored(): void
    {
        $keeper = $this->keeper();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('kolom HARGA');

        /*
         * Somebody pasting a supplier price list in here has made an
         * understandable mistake. Dropping the column silently would leave
         * them believing they had set four hundred prices; prices move by
         * publishing a price list version and never by an update.
         */
        $this->importer()->preview($this->csv(
            ['P-1,YUHOLI,HYDRAULIC PART,T,M,P,Barang,10,PCS,375000,Y,'],
            'KODE,MERK,KATEGORI,TIPE_PRODUK,MOBIL,PART_NUMBER,DESCRIPTION,QTY_PER_CTN,SATUAN_DASAR,HARGA,AKTIF,CATATAN',
        ), $keeper);
    }

    public function test_it_will_not_re_denominate_a_sku_that_already_has_stock(): void
    {
        $keeper = $this->keeper();
        $warehouse = Warehouse::factory()->create();

        Product::factory()->create(['kode' => 'UOM-1', 'satuan_dasar' => 'PCS']);
        $this->actingAs($keeper);
        app(StockLedger::class)->record('UOM-1', $warehouse->id, 200, MovementReason::Penerimaan);

        $rows = $this->importer()->preview($this->csv([
            'UOM-1,YUHOLI,HYDRAULIC PART,T,M,P,Ganti satuan,10,SET,Y,',
        ]), $keeper);

        /*
         * 200 PCS silently becoming 200 SET is the whole stock ledger
         * re-denominated by a spreadsheet. Held, with the way out named.
         */
        $this->assertTrue($rows[0]->tertahan());
        $this->assertStringContainsString('tidak bisa diubah', implode(' ', $rows[0]->alasan));
        $this->assertSame('PCS', Product::query()->whereKey('UOM-1')->sole()->satuan_dasar);
    }

    public function test_changing_the_unit_is_fine_while_nothing_has_happened_yet(): void
    {
        $keeper = $this->keeper();
        Product::factory()->create(['kode' => 'UOM-2', 'satuan_dasar' => 'PCS']);

        $hasil = $this->importer()->import($this->csv([
            'UOM-2,YUHOLI,HYDRAULIC PART,T,M,P,Belum ada riwayat,10,SET,Y,',
        ]), $keeper);

        // A typo corrected the same morning. The guard is about history.
        $this->assertSame(1, $hasil['diperbarui']);
        $this->assertSame('SET', Product::query()->whereKey('UOM-2')->sole()->satuan_dasar);
    }

    public function test_only_the_catalogue_keeper_may_import(): void
    {
        foreach ([Role::Sales, Role::Marketing, Role::Finance, Role::Storage] as $role) {
            $actor = User::factory()->create(['role' => $role->value, 'is_active' => true]);

            try {
                $this->importer()->preview($this->csv([
                    'X-1,YUHOLI,HYDRAULIC PART,T,M,P,Barang,10,PCS,Y,',
                ]), $actor);

                $this->fail("{$role->value} seharusnya tidak boleh mengimpor katalog.");
            } catch (DomainException $e) {
                $this->assertStringContainsString('tidak berhak mengelola katalog', $e->getMessage());
            }
        }
    }

    // --- the file people actually produce -----------------------------------

    public function test_a_semicolon_file_from_indonesian_excel_reads(): void
    {
        $keeper = $this->keeper();

        $rows = $this->importer()->preview(
            "KODE;MERK;KATEGORI;TIPE_PRODUK;MOBIL;PART_NUMBER;DESCRIPTION;QTY_PER_CTN;SATUAN_DASAR;AKTIF;CATATAN\n"
            ."SEMI-1;YUHOLI;HYDRAULIC PART;T;M;P;Dari Excel;10;PCS;Y;\n",
            $keeper,
        );

        // Excel in an Indonesian locale saves semicolons, and a file refused
        // for that reads as "the importer is broken".
        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]->tertahan());
        $this->assertSame('SEMI-1', $rows[0]->kode);
    }

    public function test_case_and_spacing_in_brand_and_category_are_forgiven(): void
    {
        $keeper = $this->keeper();

        $rows = $this->importer()->preview($this->csv([
            'CASE-1,yuholi,  hydraulic   part ,T,M,P,Ejaan bebas,10,pcs,y,',
        ]), $keeper);

        $this->assertFalse($rows[0]->tertahan(), implode(' | ', $rows[0]->alasan));
        // Stored canonically whatever the file said.
        $this->assertSame('YUHOLI', $rows[0]->nilai['merk']);
        $this->assertSame('HYDRAULIC PART', $rows[0]->nilai['kategori']);
        $this->assertSame('PCS', $rows[0]->nilai['satuan_dasar']);
    }

    public function test_a_header_missing_a_required_column_says_which(): void
    {
        $keeper = $this->keeper();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('kolom SATUAN_DASAR');

        $this->importer()->preview($this->csv(
            ['H-1,YUHOLI,HYDRAULIC PART,T,M,P,Tanpa satuan,10,Y,'],
            'KODE,MERK,KATEGORI,TIPE_PRODUK,MOBIL,PART_NUMBER,DESCRIPTION,QTY_PER_CTN,AKTIF,CATATAN',
        ), $keeper);
    }

    // --- the template ------------------------------------------------------

    public function test_the_example_file_is_one_the_importer_accepts(): void
    {
        $keeper = $this->keeper();

        $csv = app(CsvTemplate::class)->toCsv(TemplateKind::Barang);
        $rows = $this->importer()->preview($csv, $keeper);

        /*
         * The template is generated from the same column list the parser
         * reads, and this is what makes that worth anything: the example
         * somebody downloads round-trips through the importer with nothing
         * held back, BOM and all.
         */
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertFalse($row->tertahan(), implode(' | ', $row->alasan));
        }

        $this->assertSame('SET', $rows[1]->nilai['satuan_dasar']);
    }

    public function test_the_template_carries_no_price_column(): void
    {
        $columns = app(CsvTemplate::class)->columns(TemplateKind::Barang);

        $this->assertNotContains('HARGA', $columns);
        $this->assertContains('SATUAN_DASAR', $columns);
    }
}
