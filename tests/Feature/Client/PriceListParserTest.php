<?php

namespace Tests\Feature\Client;

use App\Client\Domain\PriceList\CanonicalFileParser;
use App\Client\Domain\PriceList\CellReader;
use App\Client\Domain\PriceList\ParsedRow;
use App\Client\Domain\PriceList\RowClassifier;
use App\Client\Domain\PriceList\SupplierWorkbookParser;
use RuntimeException;
use Tests\Feature\Client\Support\Workbooks;
use Tests\TestCase;

/** The supplier's workbook is messy; the parser reads it by position, blocks what it cannot trust and annotates what it guessed. */
class PriceListParserTest extends TestCase
{
    use Workbooks;

    protected function tearDown(): void
    {
        $this->tearDownWorkbooks();
        parent::tearDown();
    }

    /** @return list<ParsedRow> */
    private function supplier(array $rows, string $category = 'HYDRAULIC PART', string $sheet = 'YUHOLI'): array
    {
        return iterator_to_array((new SupplierWorkbookParser)->parse($this->supplierWorkbook($rows, $category, $sheet)), false);
    }

    public function test_category_comes_from_the_nearest_title_row_above_and_a_missing_one_blocks(): void
    {
        $rows = iterator_to_array((new SupplierWorkbookParser)->parse($this->workbook(['S' => [
            $this->supplierHeader(),
            $this->supplierRow('YH-0'),
            ['HYDRAULIC PART'],
            $this->supplierRow('YH-1'),
            ['BRAKE MASTER'],
            $this->supplierRow('YH-2'),
            ['SUSPENSION PART'],
            $this->supplierRow('YH-3'),
        ]])), false);

        $this->assertCount(4, $rows);
        $this->assertTrue($rows[0]->has('kategori_tidak_terdeteksi'));
        $this->assertSame(ParsedRow::BLOCKER, $rows[0]->status());
        $this->assertSame('HYDRAULIC PART', $rows[1]->kategori);
        $this->assertNull($rows[1]->tipeProduk);
        $this->assertSame('HYDRAULIC PART', $rows[2]->kategori, 'a product type title does not overwrite the category');
        $this->assertSame('BRAKE MASTER', $rows[2]->tipeProduk);
        $this->assertSame('SUSPENSION PART', $rows[3]->kategori);
        $this->assertNull($rows[3]->tipeProduk, 'a new category clears the carried product type');
    }

    public function test_repeated_headers_banners_and_blank_rows_are_skipped_and_an_asterisk_type_is_a_type(): void
    {
        $rows = $this->supplier([
            $this->supplierRow('YH-1'),
            ['', '', '', '', '', '', ''],
            $this->supplierHeader(),
            ['*HARGA SEWAKTU-WAKTU DAPAT BERUBAH'],
            ['*FRONT WHEEL'],
            $this->supplierRow('YH-2'),
        ]);

        $this->assertSame(['YH-1', 'YH-2'], array_map(fn (ParsedRow $r) => $r->kode, $rows));
        $this->assertSame('*FRONT WHEEL', $rows[1]->tipeProduk);
        $this->assertSame(RowClassifier::TITLE_BANNER, RowClassifier::titleKind('PRICE LIST 2026'));
    }

    public function test_the_brand_comes_from_the_merk_column_never_the_sheet_name(): void
    {
        $rows = $this->supplier([$this->supplierRow('YH-1', merk: 'OSBORN'), $this->supplierRow('YH-2', merk: null), $this->supplierRow('YH-3', merk: 'Acme')], sheet: 'YUHOLI 2024 REV');

        $this->assertSame('OSBORN', $rows[0]->merk);
        $this->assertSame(ParsedRow::OK, $rows[0]->status());
        $this->assertNull($rows[1]->merk);
        $this->assertTrue($rows[1]->has('merk_kosong'));
        $this->assertSame('ACME', $rows[2]->merk);
        $this->assertTrue($rows[2]->has('merk_tidak_dikenal'));
        $this->assertSame(ParsedRow::NOTE, $rows[2]->status());
    }

    public function test_kode_rules(): void
    {
        $rows = $this->supplier([$this->supplierRow('YH-1 / YH-2'), $this->supplierRow(''), $this->supplierRow('yh-3'), $this->supplierRow('X')]);

        $this->assertTrue($rows[0]->has('kode_ganda'));
        $this->assertSame('YH-1', $rows[0]->kode);
        $this->assertTrue($rows[1]->has('kode_kosong'));
        $this->assertSame('YH-3', $rows[2]->kode, 'upper-cased');
        $this->assertSame(ParsedRow::OK, $rows[2]->status());
        $this->assertTrue($rows[3]->has('kode_tidak_standar'), 'two characters at least');
    }

    public function test_qty_per_ctn_rules(): void
    {
        $rows = $this->supplier([
            $this->supplierRow('A1', qty: '18 / 10'),
            $this->supplierRow('A2', qty: '26-22-55'),
            $this->supplierRow('A3', qty: '26-29-55,5'),
            $this->supplierRow('A4', qty: '262255'),
            $this->supplierRow('A5', qty: '400'),
            $this->supplierRow('A6', qty: null),
            $this->supplierRow('A7', qty: 'FULL KIT'),
            $this->supplierRow('A8', qty: '0'),
        ]);

        $this->assertTrue($rows[0]->has('qty_ctn_ganda'));
        $this->assertSame(18, $rows[0]->qtyPerCtn);
        $this->assertTrue($rows[1]->has('qty_ctn_ganda'), 'dimensions are not a carton size');
        $this->assertStringContainsString('26-22-55', $rows[1]->issues[0]['message']);
        $this->assertTrue($rows[2]->has('qty_ctn_ganda'));
        $this->assertTrue($rows[3]->has('qty_ctn_tidak_masuk_akal'));
        $this->assertSame(1, $rows[3]->qtyPerCtn);
        $this->assertSame(400, $rows[4]->qtyPerCtn);
        $this->assertSame(ParsedRow::OK, $rows[4]->status());
        $this->assertSame(1, $rows[5]->qtyPerCtn);
        $this->assertTrue($rows[5]->has('qty_ctn_kosong'));
        $this->assertStringContainsString('QTY/CTN is empty', (string) $rows[5]->catatanWithNotes());
        $this->assertTrue($rows[6]->has('qty_ctn_bukan_angka'));
        $this->assertStringContainsString('FULL KIT', (string) $rows[6]->catatanWithNotes());
        $this->assertStringNotContainsString('empty', (string) $rows[6]->catatanWithNotes());
        $this->assertTrue($rows[7]->has('qty_ctn_bukan_angka'), 'a zero is not a carton');
        $this->assertSame('PCS', $rows[0]->satuanDasar, 'the parser never guesses a base unit');
    }

    public function test_price_rules(): void
    {
        $rows = $this->supplier([
            $this->supplierRow('A1', harga: 'TANYA SALES'),
            $this->supplierRow('A2', harga: 'Rp 1.250.000'),
            $this->supplierRow('A3', harga: '1,250,000'),
            $this->supplierRow('A4', harga: 875000),
            $this->supplierRow('A5', harga: 0),
            $this->supplierRow('A6', harga: '1.250,50'),
        ]);

        $this->assertTrue($rows[0]->has('harga_tidak_valid'));
        $this->assertNull($rows[0]->harga);
        $this->assertSame(1_250_000, $rows[1]->harga);
        $this->assertSame(1_250_000, $rows[2]->harga);
        $this->assertSame(875_000, $rows[3]->harga);
        $this->assertTrue($rows[4]->has('harga_nol'));
        $this->assertSame(1_251, $rows[5]->harga);
        $this->assertSame(1_250_000, CellReader::parseHarga('1.250.000,00'));
    }

    public function test_phantom_columns_are_trimmed_and_columns_are_read_by_position_even_when_the_header_lies(): void
    {
        $wide = array_merge($this->supplierRow('A1'), array_fill(0, 183, 'x'));
        $lyingHeader = ['KODE', 'HARGA', 'MOBIL', 'QTY/CTN', 'DESCRIPTION', 'MERK', 'PART NUMBER'];
        $rows = $this->supplier([$wide, $lyingHeader, $this->supplierRow('A2', harga: 50_000, merk: 'ASTRO', qty: 6, description: 'Shock', mobil: 'XENIA', partNumber: 'PN-9')]);

        $this->assertCount(2, $rows);
        $this->assertLessThanOrEqual(24, count($rows[0]->raw));
        $this->assertSame('A2', $rows[1]->kode);
        $this->assertSame(50_000, $rows[1]->harga);
        $this->assertSame('ASTRO', $rows[1]->merk);
        $this->assertSame(6, $rows[1]->qtyPerCtn);
        $this->assertSame('Shock', $rows[1]->description);
        $this->assertSame('XENIA', $rows[1]->mobil);
        $this->assertSame('PN-9', $rows[1]->partNumber);
    }

    public function test_line_breaks_inside_cells_are_flattened(): void
    {
        $rows = $this->supplier([$this->supplierRow('A1', description: "Brake\nmaster  cylinder")]);

        $this->assertSame('Brake master cylinder', $rows[0]->description);
    }

    public function test_the_canonical_file_needs_the_exact_header_and_reads_aktif(): void
    {
        $rows = iterator_to_array((new CanonicalFileParser)->parse($this->canonicalWorkbook([
            $this->canonicalRow('A1'),
            $this->canonicalRow('A2', aktif: 'N'),
            $this->canonicalRow('A3', aktif: ''),
            $this->canonicalRow('A4', satuan: 'BOX'),
            $this->canonicalRow('A5', merk: '', kategori: ''),
            ['', '', '', '', '', '', '', '', '', '', '', ''],
        ])), false);

        $this->assertCount(5, $rows);
        $this->assertTrue($rows[0]->aktif);
        $this->assertFalse($rows[1]->aktif);
        $this->assertTrue($rows[2]->aktif, 'blank is yes');
        $this->assertTrue($rows[3]->has('satuan_tidak_dikenal'));
        $this->assertSame('PCS', $rows[3]->satuanDasar);
        $this->assertTrue($rows[4]->has('merk_kosong'));
        $this->assertTrue($rows[4]->has('kategori_tidak_terdeteksi'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('standard format');
        iterator_to_array((new CanonicalFileParser)->parse($this->workbook(['Sheet1' => [['KODE', 'HARGA'], ['A1', 1]]])), false);
    }

    public function test_a_csv_reads_like_a_workbook(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pl').'.csv';
        $this->scratchFiles[] = $path;
        file_put_contents($path, "KODE;MERK;KATEGORI;TIPE_PRODUK;MOBIL;PART_NUMBER;DESCRIPTION;QTY_PER_CTN;SATUAN_DASAR;HARGA;AKTIF;CATATAN\nA1;YUHOLI;HYDRAULIC PART;;AVANZA;PN;Widget;12;PCS;100000;Y;\n");

        $rows = iterator_to_array((new CanonicalFileParser)->parse($path), false);
        $this->assertCount(1, $rows);
        $this->assertSame('A1', $rows[0]->kode);
        $this->assertSame(100_000, $rows[0]->harga);
    }
}
