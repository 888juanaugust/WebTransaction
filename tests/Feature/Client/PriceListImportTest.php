<?php

namespace Tests\Feature\Client;

use App\Client\Domain\PriceList\CanonicalFileParser;
use App\Client\Domain\PriceList\PriceListExporter;
use App\Client\Domain\PriceList\PriceListImporter;
use App\Client\Domain\PriceList\PriceListPublisher;
use App\Client\Jobs\ParsePriceListImport;
use App\Client\Models\PriceListImport;
use App\Client\Models\PriceListImportRow;
use App\Client\Models\PriceListItem;
use App\Client\Models\PriceListVersion;
use App\Models\Company\AuditLog;
use App\Models\Inventory\Item;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Client\Support\Workbooks;
use Tests\TestCase;

/** Upload → rows → diff → brake → publish as a version that never changes a price; what the file does not name is carried forward. */
class PriceListImportTest extends TestCase
{
    use Workbooks;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-17 10:00:00'));
        $this->seed();
        $this->owner = $this->actingAsAdmin();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        $this->tearDownWorkbooks();
        parent::tearDown();
    }

    /** An import of a workbook, stored as an upload would be, parsed. */
    private function import(string $path, string $format = PriceListImport::SUPPLIER, bool $full = false): PriceListImport
    {
        $stored = 'price-lists/'.basename($path);
        Storage::disk('local')->put($stored, (string) file_get_contents($path));
        $import = PriceListImport::query()->create(['original_filename' => basename($path), 'stored_path' => $stored, 'checksum' => hash_file('sha256', $path), 'format' => $format, 'is_full_replacement' => $full, 'status' => PriceListImport::UPLOADED, 'uploaded_by' => $this->owner->id]);
        (new ParsePriceListImport($import->id))->handle(app(PriceListImporter::class));

        return $import->fresh();
    }

    /** Small lists trip the brake on any change; the tests acknowledge it unless they test it. */
    private function published(array $rows): PriceListVersion
    {
        return app(PriceListPublisher::class)->publish($this->import($this->supplierWorkbook($rows)), $this->owner, null, 'test');
    }

    /** @return array<string, int> the buckets with their keys in a fixed order (jsonb keeps its own) */
    private function buckets(PriceListImport $import): array
    {
        $b = $import->diff['buckets'];
        ksort($b);

        return $b;
    }

    public function test_parsing_stages_the_rows_counts_the_issues_and_blocks_a_duplicate_kode(): void
    {
        $import = $this->import($this->supplierWorkbook([
            $this->supplierRow('A1', 100_000),
            $this->supplierRow('A2', 'x'),
            $this->supplierRow('A3', 100_000, qty: null),
            $this->supplierRow('A1', 120_000),
        ]));

        $this->assertSame(PriceListImport::PARSED, $import->status);
        $this->assertSame(4, $import->row_count);
        $this->assertSame(2, $import->blocker_count);
        $this->assertSame(1, $import->note_count);
        $duplicate = $import->rows()->where('row_number', 7)->sole();
        $this->assertSame(PriceListImportRow::BLOCKER, $duplicate->status);
        $this->assertSame('kode_duplikat', $duplicate->issues[0]['code']);
        $this->assertStringContainsString('row 4', $duplicate->issues[0]['message']);
        $this->assertSame('YUHOLI', $duplicate->sheet);

        // Parsing again replaces the rows rather than doubling them.
        (new ParsePriceListImport($import->id))->handle(app(PriceListImporter::class));
        $this->assertSame(4, $import->rows()->count());
    }

    public function test_a_file_that_cannot_be_read_fails_the_import_with_the_reason(): void
    {
        try {
            $this->import($this->workbook(['Sheet1' => [['KODE', 'HARGA'], ['A1', 1]]]), PriceListImport::CANONICAL);
            $this->fail('the job rethrows so the queue records the failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('standard format', $e->getMessage());
        }
        $import = PriceListImport::query()->sole();
        $this->assertSame(PriceListImport::FAILED, $import->status);
        $this->assertStringContainsString('standard format', (string) $import->parse_error);
    }

    public function test_the_diff_buckets_every_sku_against_the_list_in_force(): void
    {
        $this->published([$this->supplierRow('A1', 100_000), $this->supplierRow('A2', 200_000), $this->supplierRow('GONE', 300_000)]);

        $import = $this->import($this->supplierWorkbook([
            $this->supplierRow('A1', 100_000),
            $this->supplierRow('A2', 250_000),
            $this->supplierRow('NEW', 50_000),
            $this->supplierRow('BAD', 'x'),
        ]));

        $this->assertSame(['error' => 1, 'harga_berubah' => 1, 'sku_baru' => 1, 'tidak_ada_di_file' => 1, 'tidak_berubah' => 1], $this->buckets($import));
        $this->assertSame(5_000, $import->diff['changed_share_bps']);
        $this->assertSame(PriceListImportRow::CHANGED, $import->rows()->where('kode', 'A2')->value('diff_bucket'));
        $this->assertSame(200_000, $import->rows()->where('kode', 'A2')->value('harga_lama'));
        $this->assertEquals(['kode' => 'A2', 'harga_lama' => 200_000, 'harga_baru' => 250_000, 'delta_bps' => 2_500], $import->diff['biggest_moves'][0]);
        $this->assertTrue($import->diff['brake_tripped'], 'half the prices changed');
    }

    public function test_the_brake_trips_on_one_price_moving_more_than_half_and_publishing_needs_the_acknowledgement(): void
    {
        $this->published([$this->supplierRow('A1', 100_000), $this->supplierRow('A2', 100_000), $this->supplierRow('A3', 100_000), $this->supplierRow('A4', 100_000), $this->supplierRow('A5', 100_000), $this->supplierRow('A6', 100_000)]);
        $import = $this->import($this->supplierWorkbook([
            $this->supplierRow('A1', 300_000), $this->supplierRow('A2', 100_000), $this->supplierRow('A3', 100_000), $this->supplierRow('A4', 100_000), $this->supplierRow('A5', 100_000), $this->supplierRow('A6', 100_000),
        ]));

        $this->assertTrue($import->diff['brake_tripped']);
        $this->assertStringContainsString('100.000', $import->diff['brake_reasons'][0]);
        $this->assertStringContainsString('300.000', $import->diff['brake_reasons'][0]);

        try {
            app(PriceListPublisher::class)->publish($import, $this->owner);
            $this->fail('the brake needs a second confirmation');
        } catch (DomainException $e) {
            $this->assertStringContainsString('second confirmation', $e->getMessage());
        }
        $this->assertSame(PriceListImport::PARSED, $import->fresh()->status);

        $version = app(PriceListPublisher::class)->publish($import->fresh(), $this->owner, null, 'checked with the supplier');
        $this->assertSame(PriceListVersion::PUBLISHED, $version->status);
        $this->assertSame('checked with the supplier', $import->fresh()->brake_acknowledgement);
    }

    public function test_publishing_creates_items_and_a_version_and_never_updates_an_earlier_price(): void
    {
        $first = $this->published([$this->supplierRow('A1', 100_000, qty: 12, description: 'Brake pad', merk: 'OSBORN')]);
        $item = Item::query()->where('number', 'A1')->sole();
        $this->assertSame('Brake pad', $item->name);
        $this->assertSame('OSBORN', $item->brand->name);
        $this->assertSame('HYDRAULIC PART', $item->category->name);
        $this->assertSame('AVANZA', $item->vehicle);
        $this->assertSame('PN-1', $item->part_number);
        $this->assertSame('PCS', $item->unit1->name);
        $this->assertSame('12.000000', $item->units()->first()->ratio, 'a carton unit at the file\'s ratio');
        $this->assertSame(100_000, $item->sell_price, 'the cached list price');

        $second = $this->published([$this->supplierRow('A1', 120_000, qty: 24, description: 'Brake pad set')]);

        $this->assertSame(100_000, PriceListItem::query()->where('version_id', $first->id)->sole()->price, 'the old version is untouched');
        $this->assertSame(120_000, PriceListItem::query()->where('version_id', $second->id)->sole()->price);
        $this->assertSame(PriceListVersion::SUPERSEDED, $first->fresh()->status);
        $this->assertSame($second->id, PriceListVersion::current()->id);
        $item->refresh();
        $this->assertSame('Brake pad set', $item->name, 'descriptive fields follow the file');
        $this->assertSame('24.000000', $item->units()->first()->ratio);
        $this->assertSame(120_000, $item->sell_price);
        $this->assertSame(1, Item::query()->where('number', 'A1')->count());
        $this->assertNotNull(AuditLog::query()->where('action', 'price_list_published')->where('document_id', $second->id)->first());
    }

    public function test_blocker_rows_are_never_published(): void
    {
        $version = $this->published([$this->supplierRow('A1', 100_000), $this->supplierRow('A2', 'x'), $this->supplierRow('A3 / A4', 100_000)]);

        $this->assertSame(['A1'], $version->items()->with('item')->get()->map(fn ($i) => $i->item->number)->all());
        $this->assertFalse(Item::query()->where('number', 'A2')->exists());
    }

    public function test_skus_missing_from_the_file_are_carried_forward_unless_the_file_is_a_full_replacement(): void
    {
        $this->published([$this->supplierRow('A1', 100_000), $this->supplierRow('A2', 200_000)]);

        $second = app(PriceListPublisher::class)->publish($this->import($this->supplierWorkbook([$this->supplierRow('A1', 110_000)])), $this->owner, null, 'test');
        $carried = PriceListItem::query()->where('version_id', $second->id)->whereHas('item', fn ($q) => $q->where('number', 'A2'))->sole();
        $this->assertSame(200_000, $carried->price);
        $this->assertTrue($carried->is_active);
        $this->assertTrue(Item::query()->where('number', 'A2')->sole()->is_active);

        $third = app(PriceListPublisher::class)->publish($this->import($this->supplierWorkbook([$this->supplierRow('A1', 110_000)]), full: true), $this->owner, null, 'test');
        $dropped = PriceListItem::query()->where('version_id', $third->id)->whereHas('item', fn ($q) => $q->where('number', 'A2'))->sole();
        $this->assertFalse($dropped->is_active, 'a full replacement switches the missing SKU off');
        $this->assertFalse(Item::query()->where('number', 'A2')->sole()->is_active);
        $this->assertTrue(Item::query()->where('number', 'A1')->sole()->is_active);
    }

    public function test_publishing_twice_or_a_discarded_import_is_refused(): void
    {
        $import = $this->import($this->supplierWorkbook([$this->supplierRow('A1', 100_000)]));
        app(PriceListPublisher::class)->publish($import, $this->owner);

        $this->expectException(DomainException::class);
        app(PriceListPublisher::class)->publish($import->fresh(), $this->owner);
    }

    public function test_the_export_is_the_import_format_and_round_trips(): void
    {
        $others = array_map(fn (int $n) => $this->supplierRow("B{$n}", 50_000, merk: 'ASTRO'), [1, 2, 3, 4, 5]);
        $version = $this->published([$this->supplierRow('A1', 100_000, qty: 12, description: 'Brake pad'), $this->supplierRow('A2', 200_000, merk: 'ASTRO'), ...$others]);
        $file = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        $this->scratchFiles[] = $file;

        app(PriceListExporter::class)->write($version, $file);
        $rows = iterator_to_array((new CanonicalFileParser)->parse($file), false);

        $this->assertCount(7, $rows);
        $this->assertSame(['A1', 'YUHOLI', 'HYDRAULIC PART', null, 'AVANZA', 'PN-1', 'Brake pad', 12, 'PCS', 100_000, true], [$rows[0]->kode, $rows[0]->merk, $rows[0]->kategori, $rows[0]->tipeProduk, $rows[0]->mobil, $rows[0]->partNumber, $rows[0]->description, $rows[0]->qtyPerCtn, $rows[0]->satuanDasar, $rows[0]->harga, $rows[0]->aktif]);

        // Edit HARGA, re-import: only that row changes.
        $edited = $this->canonicalWorkbook([$this->canonicalRow('A1', 105_000, description: 'Brake pad'), $this->canonicalRow('A2', 200_000, merk: 'ASTRO'), ...array_map(fn (int $n) => $this->canonicalRow("B{$n}", 50_000, merk: 'ASTRO'), [1, 2, 3, 4, 5])]);
        $import = $this->import($edited, PriceListImport::CANONICAL);
        $this->assertSame(['error' => 0, 'harga_berubah' => 1, 'sku_baru' => 0, 'tidak_ada_di_file' => 0, 'tidak_berubah' => 6], $this->buckets($import));
        $this->assertFalse($import->diff['brake_tripped']);
    }

    public function test_a_legacy_xls_workbook_is_refused_with_advice(): void
    {
        $path = sys_get_temp_dir().'/legacy-'.uniqid().'.xls';
        file_put_contents($path, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 512));

        try {
            $this->import($path, PriceListImport::CANONICAL);
            $this->fail('a legacy workbook is refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('.xlsx', $e->getMessage());
        }

        $import = PriceListImport::query()->latest('id')->firstOrFail();
        $this->assertSame(PriceListImport::FAILED, $import->status);
        $this->assertStringContainsString('.xlsx', (string) $import->parse_error);
        @unlink($path);
    }
}
