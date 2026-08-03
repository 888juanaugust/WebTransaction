<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\PriceList\PriceListImporter;
use App\Domain\PriceList\SupplierWorkbookParser;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * The supplier workbook is messy in known, specific ways. Each quirk gets a
 * test, because "the importer coped" is not something you want to discover on
 * a live price list.
 */
class PriceListImportTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function tearDown(): void
    {
        if (isset($this->path) && file_exists($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    /**
     * Build a workbook shaped like the supplier's: a sheet whose name is not a
     * brand, a category as a title-only row, repeated headers mid-file, and
     * the MERK column as the only authority on brand.
     *
     * @param  list<list<string|int|null>>  $rows
     */
    private function workbook(array $rows, string $sheetName = 'Sheet1'): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetName);

        foreach ($rows as $r => $cells) {
            foreach ($cells as $c => $value) {
                if ($value !== null && $value !== '') {
                    $sheet->setCellValue([$c + 1, $r + 1], $value);
                }
            }
        }

        $this->path = tempnam(sys_get_temp_dir(), 'pl_').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($this->path);

        return $this->path;
    }

    /** Columns follow config('pricelist.supplier_layout'). */
    private function dataRow(
        string $kode,
        string $merk = 'YUHOLI',
        string|int $qtyPerCtn = 12,
        string|int $harga = 150000,
    ): array {
        return [$kode, 'SHOCK ABSORBER', 'AVANZA', 'PN-1', 'Deskripsi', $qtyPerCtn, $harga, $merk];
    }

    private function headerRow(): array
    {
        return ['KODE', 'TIPE', 'MOBIL', 'PART NUMBER', 'DESCRIPTION', 'QTY/CTN', 'HARGA', 'MERK'];
    }

    /** @return list<\App\Domain\PriceList\ParsedRow> */
    private function parse(string $path): array
    {
        return iterator_to_array(app(SupplierWorkbookParser::class)->parse($path), false);
    }

    private function issueCodes(\App\Domain\PriceList\ParsedRow $row): array
    {
        return array_column($row->issues, 'code');
    }

    // --- the known quirks --------------------------------------------------

    public function test_category_comes_from_the_nearest_title_row_above(): void
    {
        $rows = $this->parse($this->workbook([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1'),
            ['SUSPENSION PART'],
            $this->dataRow('YH-2'),
        ]));

        $this->assertCount(2, $rows);
        $this->assertSame('HYDRAULIC PART', $rows[0]->kategori);
        $this->assertSame('SUSPENSION PART', $rows[1]->kategori);
    }

    public function test_a_row_with_no_category_above_it_is_a_blocker(): void
    {
        $rows = $this->parse($this->workbook([
            $this->headerRow(),
            $this->dataRow('YH-1'),
        ]));

        $this->assertTrue($rows[0]->hasBlocker());
        $this->assertContains('kategori_tidak_terdeteksi', $this->issueCodes($rows[0]));
    }

    public function test_repeated_headers_mid_file_are_skipped(): void
    {
        $rows = $this->parse($this->workbook([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1'),
            $this->headerRow(),
            $this->dataRow('YH-2'),
            $this->headerRow(),
            $this->dataRow('YH-3'),
        ]));

        $this->assertCount(3, $rows);
        $this->assertSame(['YH-1', 'YH-2', 'YH-3'], array_column($rows, 'kode'));
    }

    public function test_brand_comes_from_the_merk_column_not_the_sheet_name(): void
    {
        $rows = $this->parse($this->workbook([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1', merk: 'OSBORN'),
        ], sheetName: 'YUHOLI 2024 REV'));

        // The sheet is called YUHOLI; the row is an OSBORN part.
        $this->assertSame('OSBORN', $rows[0]->merk);
    }

    public function test_a_missing_merk_is_a_blocker_and_never_guessed_from_the_sheet(): void
    {
        $rows = $this->parse($this->workbook([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1', merk: ''),
        ], sheetName: 'ASTRO'));

        $this->assertTrue($rows[0]->hasBlocker());
        $this->assertContains('merk_kosong', $this->issueCodes($rows[0]));
        $this->assertNull($rows[0]->merk);
    }

    public function test_a_kode_holding_two_skus_is_a_blocker_not_an_auto_split(): void
    {
        $rows = $this->parse($this->workbook([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1 / YH-2'),
        ]));

        $this->assertTrue($rows[0]->hasBlocker());
        $this->assertContains('kode_ganda', $this->issueCodes($rows[0]));
    }

    public function test_a_double_qty_per_ctn_is_a_blocker(): void
    {
        $rows = $this->parse($this->workbook([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1', qtyPerCtn: '18 / 10'),
        ]));

        $this->assertTrue($rows[0]->hasBlocker());
        $this->assertContains('qty_ctn_ganda', $this->issueCodes($rows[0]));
    }

    public function test_a_blank_qty_per_ctn_defaults_to_one_and_is_annotated(): void
    {
        $rows = $this->parse($this->workbook([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1', qtyPerCtn: ''),
        ]));

        $this->assertFalse($rows[0]->hasBlocker(), 'blank QTY/CTN imports anyway');
        $this->assertSame(1, $rows[0]->qtyPerCtn);
        $this->assertContains('qty_ctn_kosong', $this->issueCodes($rows[0]));
        $this->assertStringContainsString('QTY/CTN kosong', $rows[0]->catatanWithNotes());
    }

    public function test_a_non_numeric_price_is_a_blocker(): void
    {
        $rows = $this->parse($this->workbook([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1', harga: 'TANYA SALES'),
        ]));

        $this->assertTrue($rows[0]->hasBlocker());
        $this->assertContains('harga_tidak_valid', $this->issueCodes($rows[0]));
        $this->assertNull($rows[0]->harga);
    }

    public function test_formatted_rupiah_prices_parse_to_integers(): void
    {
        $rows = $this->parse($this->workbook([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1', harga: 'Rp 1.250.000'),
            $this->dataRow('YH-2', harga: '1,250,000'),
            $this->dataRow('YH-3', harga: 875000),
        ]));

        $this->assertSame(1_250_000, $rows[0]->harga);
        $this->assertSame(1_250_000, $rows[1]->harga);
        $this->assertSame(875_000, $rows[2]->harga);
    }

    public function test_phantom_columns_are_trimmed_before_parsing(): void
    {
        $wide = array_merge(
            $this->dataRow('YH-1'),
            array_fill(0, 183, 'x'),
        );

        $rows = $this->parse($this->workbook([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $wide,
        ]));

        $this->assertCount(1, $rows);
        $this->assertSame('YH-1', $rows[0]->kode);
        $this->assertLessThanOrEqual(
            (int) config('pricelist.max_columns'),
            count($rows[0]->raw),
        );
    }

    public function test_columns_are_mapped_by_position_even_when_the_header_lies(): void
    {
        // A mislabeled header of the kind found around rows 872 and 884: the
        // text says HARGA where the data is a part number. Mapping by header
        // text would put a part number in the price column.
        $rows = $this->parse($this->workbook([
            ['HYDRAULIC PART'],
            ['HARGA', 'KODE', 'QTY/CTN', 'MERK', 'DESCRIPTION', 'MOBIL', 'TIPE', 'PART NUMBER'],
            $this->dataRow('YH-1', harga: 150000),
        ]));

        $this->assertSame('YH-1', $rows[0]->kode);
        $this->assertSame(150_000, $rows[0]->harga);
        $this->assertSame('YUHOLI', $rows[0]->merk);
    }

    public function test_blank_rows_are_ignored(): void
    {
        $rows = $this->parse($this->workbook([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1'),
            [],
            [null, null, null],
            $this->dataRow('YH-2'),
        ]));

        $this->assertCount(2, $rows);
    }

    // --- staging, diff and publishing --------------------------------------

    private function stage(array $rows): PriceListImport
    {
        $path = $this->workbook($rows);

        $import = PriceListImport::create([
            'original_filename' => 'PL_JAVA_IMPORT.xlsx',
            'stored_path' => $path,
            'uploaded_by' => User::factory()->owner()->create()->id,
            'status' => PriceListImport::STATUS_UPLOADED,
        ]);

        app(PriceListImporter::class)->parseToStaging($import, $path, canonical: false);

        return $import->refresh();
    }

    public function test_a_duplicate_kode_is_a_blocker(): void
    {
        $import = $this->stage([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1'),
            $this->dataRow('YH-1', harga: 999000),
        ]);

        $this->assertSame(2, $import->row_count);
        $this->assertSame(1, $import->blocker_count);

        $blocker = $import->rows()->where('status', PriceListImportRow::STATUS_BLOCKER)->sole();
        $this->assertSame('kode_duplikat', $blocker->issues[0]['code']);
    }

    public function test_re_parsing_replaces_staging_rows_rather_than_duplicating_them(): void
    {
        $rows = [
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1'),
        ];

        $import = $this->stage($rows);
        app(PriceListImporter::class)->parseToStaging($import, $this->path, canonical: false);

        $this->assertSame(1, $import->refresh()->rows()->count());
    }

    public function test_the_diff_buckets_every_sku(): void
    {
        $previous = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        foreach ([['YH-1', 150_000], ['YH-2', 200_000], ['YH-GONE', 300_000]] as [$kode, $harga]) {
            PriceListItem::factory()->create([
                'version_id' => $previous->id,
                'kode' => $kode,
                'harga' => $harga,
            ]);
        }

        $import = $this->stage([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1', harga: 150000),   // unchanged
            $this->dataRow('YH-2', harga: 220000),   // changed
            $this->dataRow('YH-3', harga: 90000),    // new
            $this->dataRow('YH-4', harga: 'NANTI'),  // error
        ]);

        $buckets = $import->diff['buckets'];

        $this->assertSame(1, $buckets['tidak_berubah']);
        $this->assertSame(1, $buckets['harga_berubah']);
        $this->assertSame(1, $buckets['sku_baru']);
        $this->assertSame(1, $buckets['error']);
        $this->assertSame(1, $buckets['tidak_ada_di_file'], 'YH-GONE is missing from the file');
    }

    public function test_the_safety_brake_trips_on_a_single_price_moving_more_than_half(): void
    {
        $previous = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        PriceListItem::factory()->create([
            'version_id' => $previous->id, 'kode' => 'YH-1', 'harga' => 100_000,
        ]);

        $import = $this->stage([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1', harga: 300000),
        ]);

        $this->assertTrue($import->diff['brake_tripped']);
        // The confirmation must name the numbers, not just say "are you sure".
        $this->assertStringContainsString('100.000', implode(' ', $import->diff['brake_reasons']));
        $this->assertStringContainsString('300.000', implode(' ', $import->diff['brake_reasons']));
    }

    public function test_publishing_a_braked_import_without_acknowledgement_is_refused(): void
    {
        $previous = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subMonth()->toDateString(),
        ]);
        PriceListItem::factory()->create([
            'version_id' => $previous->id, 'kode' => 'YH-1', 'harga' => 100_000,
        ]);

        $import = $this->stage([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1', harga: 300000),
        ]);

        $this->expectException(DomainException::class);
        app(PriceListImporter::class)->publish($import, User::factory()->owner()->create(), now());
    }

    public function test_publishing_creates_a_new_version_and_never_updates_a_price(): void
    {
        $previous = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        // A routine update: one price out of ten moves 10%, so the safety
        // brake stays out of the way.
        foreach (range(1, 10) as $n) {
            PriceListItem::factory()->create([
                'version_id' => $previous->id, 'kode' => "YH-{$n}", 'harga' => 100_000,
            ]);
        }

        $fileRows = [['HYDRAULIC PART'], $this->headerRow(), $this->dataRow('YH-1', harga: 110000)];

        foreach (range(2, 10) as $n) {
            $fileRows[] = $this->dataRow("YH-{$n}", harga: 100000);
        }

        $import = $this->stage($fileRows);

        $this->assertFalse($import->diff['brake_tripped']);

        $version = app(PriceListImporter::class)->publish(
            $import,
            User::factory()->owner()->create(),
            now(),
        );

        // The old row is exactly as it was.
        $this->assertSame(100_000, PriceListItem::where('version_id', $previous->id)
            ->where('kode', 'YH-1')->value('harga'));

        $this->assertSame(110_000, PriceListItem::where('version_id', $version->id)
            ->where('kode', 'YH-1')->value('harga'));

        $this->assertSame(
            PriceListVersion::STATUS_SUPERSEDED,
            $previous->refresh()->status,
        );
    }

    public function test_blocker_rows_are_never_published(): void
    {
        $import = $this->stage([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1', harga: 110000),
            $this->dataRow('YH-2 / YH-3', harga: 120000),
        ]);

        $version = app(PriceListImporter::class)->publish(
            $import,
            User::factory()->owner()->create(),
            now(),
        );

        $kodes = PriceListItem::where('version_id', $version->id)->pluck('kode')->all();

        $this->assertSame(['YH-1'], $kodes);
    }

    public function test_skus_missing_from_the_file_are_left_alone_by_default(): void
    {
        $previous = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subMonth()->toDateString(),
        ]);
        PriceListItem::factory()->create([
            'version_id' => $previous->id, 'kode' => 'YH-GONE', 'harga' => 300_000, 'aktif' => true,
        ]);

        $import = $this->stage([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1', harga: 110000),
        ]);

        $version = app(PriceListImporter::class)->publish(
            $import,
            User::factory()->owner()->create(),
            now(),
        );

        $carried = PriceListItem::where('version_id', $version->id)->where('kode', 'YH-GONE')->sole();

        $this->assertTrue($carried->aktif, 'a SKU is never auto-deactivated for being absent');
        $this->assertSame(300_000, $carried->harga);
    }

    public function test_full_replacement_is_the_only_way_to_deactivate_missing_skus(): void
    {
        $previous = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subMonth()->toDateString(),
        ]);
        PriceListItem::factory()->create([
            'version_id' => $previous->id, 'kode' => 'YH-GONE', 'harga' => 300_000, 'aktif' => true,
        ]);

        $import = $this->stage([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1', harga: 110000),
        ]);

        $import->forceFill(['is_full_replacement' => true])->save();

        $version = app(PriceListImporter::class)->publish(
            $import,
            User::factory()->owner()->create(),
            now(),
        );

        $this->assertFalse(
            PriceListItem::where('version_id', $version->id)->where('kode', 'YH-GONE')->sole()->aktif
        );
    }

    public function test_publishing_writes_an_audit_entry(): void
    {
        $import = $this->stage([
            ['HYDRAULIC PART'],
            $this->headerRow(),
            $this->dataRow('YH-1', harga: 110000),
        ]);

        $owner = User::factory()->owner()->create();
        app(PriceListImporter::class)->publish($import, $owner, now());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'price_list_published',
            'actor_id' => $owner->id,
        ]);
    }
}
