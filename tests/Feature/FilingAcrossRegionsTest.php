<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Regions\RegionContext;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\RekapPpn;
use App\Domain\Tax\FakturBlocker;
use App\Domain\Tax\FakturExporter;
use App\Domain\Tax\FilingScope;
use App\Domain\Tax\NsfpRecorder;
use App\Models\Company;
use App\Models\FakturExport;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\Region;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * One PT, one NPWP, one SPT a month — whichever region's books took the sale.
 *
 * This is the one place in the system where region scoping is wrong by
 * construction rather than by oversight, and it is worth stating why. Regions
 * are sets of books and warehouses. They are not legal entities and they do
 * not file separately: the company has one NIB and one NPWP, and the faktur
 * pajak export and the Rekap PPN masa are both parts of a single monthly
 * return under it.
 *
 * Read region-scoped, on a month with three sales in Surabaya's books and two
 * in Jakarta's:
 *
 *     fakturs in the masa    5      PPN Rp 1.650.000
 *     the export contained   3      PPN Rp   660.000
 *     reported as blocked    0
 *     rekap PPN keluaran     Rp   660.000
 *
 * Rp 990.000 of output VAT collected from customers and not reported, and
 * nothing on the screen said so. The exporter's own docblock names that as the
 * failure it exists to prevent. Worse than a figure that argues with itself:
 * the recap and the export agreed, so the two numbers an accountant
 * cross-checks confirmed each other.
 *
 * And it was not a matter of remembering to widen the region first. Finance is
 * the role that files, and `BindRegionContext` pins Finance to the region on
 * their account and returns — there is no switcher for them to forget.
 *
 * Every test here runs pinned to one region, because that is the only state a
 * filer is ever in.
 */
class FilingAcrossRegionsTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-FK-1';

    private User $finance;

    private User $sales;

    private Region $sby;

    private Region $jkt;

    private Warehouse $gudangSby;

    private Warehouse $gudangJkt;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo('2026-08-20 09:00:00');

        // The Coretax XML names the seller; a real install has this set in
        // Pengaturan perusahaan before the first filing.
        config(['pajak.penjual.npwp' => '98.765.432.1-012.345']);

        $this->sby = $this->currentRegion();
        $this->jkt = Region::factory()->create(['kode' => 'JKT']);

        // Pinned by their account, with no switcher — see bindForStaff().
        $this->finance = User::factory()->role(Role::Finance)
            ->create(['region_id' => $this->sby->id]);
        $this->sales = User::factory()->sales()->create();

        Product::factory()->create([
            'kode' => self::SKU, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS',
            'merk' => 'YUHOLI', 'description' => 'Shock Absorber Depan',
        ]);
        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subYear()->toDateString(),
        ]);
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => self::SKU, 'harga' => 100_000,
        ]);

        $this->gudangSby = Warehouse::factory()->create(['kode' => 'GD-SBY']);
        $this->gudangJkt = app(RegionContext::class)->within(
            $this->jkt, fn () => Warehouse::factory()->create(['kode' => 'GD-JKT']),
        );

        $this->stockUp($this->gudangSby, 1000);
        app(RegionContext::class)->within(
            $this->jkt, fn () => $this->stockUp($this->gudangJkt, 1000),
        );

        foreach ([10, 20, 30] as $qty) {
            $this->invoiceFor($this->gudangSby, $qty);
        }
        app(RegionContext::class)->within($this->jkt, function () {
            foreach ([40, 50] as $qty) {
                $this->invoiceFor($this->gudangJkt, $qty);
            }
        });

        // The filer's own state.
        app(RegionContext::class)->pinTo($this->sby);
    }

    /** @return Builder<Invoice> */
    private function semua()
    {
        return Invoice::query()->withoutGlobalScope('region');
    }

    // --- the fixture really is the situation ---------------------------------

    public function test_the_month_has_fakturs_in_two_sets_of_books(): void
    {
        $this->assertSame(5, $this->semua()->count());
        $this->assertSame(1_650_000, (int) $this->semua()->sum('ppn_rupiah'));

        // And a plain scoped read is the Rp 660.000 the filing used to carry.
        $this->assertSame(3, Invoice::query()->count());
        $this->assertSame(660_000, (int) Invoice::query()->sum('ppn_rupiah'));
    }

    // --- the export ----------------------------------------------------------

    public function test_the_preview_offers_the_whole_companys_month(): void
    {
        $preview = app(FakturExporter::class)->preview(2026, 8);

        $this->assertCount(5, $preview->siap);
        $this->assertSame(1_650_000, $preview->totalPpn());
        $this->assertSame((int) $this->semua()->sum('ppn_rupiah'), $preview->totalPpn());
    }

    /**
     * The blocked list is the other half, and it broke differently.
     *
     * `Invoice::order()` was region-scoped too, so once the invoices were read
     * entity-wide the foreign ones loaded a null order, found no priced lines
     * and were reported as "no lines on the order behind this faktur". A
     * confident refusal about the wrong thing is worse than a missing row —
     * somebody goes and inspects an order that is perfectly fine.
     */
    public function test_a_faktur_from_the_other_region_is_not_reported_as_broken(): void
    {
        $preview = app(FakturExporter::class)->preview(2026, 8);

        // The reasons, not the objects — a failure here should read as the
        // sentence somebody would have gone and acted on.
        $this->assertSame(
            [],
            array_map(fn (FakturBlocker $b) => $b->invoice->nomor.': '.$b->alasan, $preview->terhalang),
        );
    }

    public function test_the_file_carries_every_faktur_and_the_month_is_fully_stamped(): void
    {
        $export = app(FakturExporter::class)->export(2026, 8, $this->finance);

        $this->assertSame(5, (int) $export->jumlah_faktur);
        $this->assertSame(1_650_000, (int) $export->total_ppn_rupiah);

        // Stamped entity-wide as well as selected entity-wide. Stamped
        // narrower, the foreign fakturs would go into the file and still look
        // unexported — offered again next month, and the month after.
        $this->assertSame(0, $this->semua()->whereNull('faktur_exported_at')->count());

        // And a second filing of the same masa now finds nothing left.
        $this->expectException(DomainException::class);
        app(FakturExporter::class)->export(2026, 8, $this->finance);
    }

    public function test_the_period_picker_offers_months_from_every_regions_books(): void
    {
        // A masa whose only fakturs live in the other region must still be
        // offered, or it can never be filed at all.
        app(RegionContext::class)->within($this->jkt, function () {
            $this->travelTo('2026-07-15 09:00:00');
            $this->invoiceFor($this->gudangJkt, 5);
            $this->travelTo('2026-08-20 09:00:00');
        });

        $this->assertContains('2026-07', app(FakturExporter::class)->availablePeriods());
    }

    // --- the recap the accountant files from ---------------------------------

    public function test_the_ppn_recap_agrees_with_the_export(): void
    {
        $rekap = app(RekapPpn::class)->build(Period::between('2026-08-01', '2026-08-31'));
        $keluaran = collect($rekap->rows)->firstWhere('pos', 'PPN Keluaran — faktur terbit');

        $this->assertSame(1_650_000, (int) $keluaran['jumlah']);
        $this->assertSame(
            app(FakturExporter::class)->preview(2026, 8)->totalPpn(),
            (int) $keluaran['jumlah'],
        );
    }

    // --- the serials coming back ---------------------------------------------

    public function test_a_serial_lands_on_a_faktur_booked_in_the_other_region(): void
    {
        /*
         * The write went through `$line->invoice?->…`, and the relation was
         * region-scoped — so for a foreign faktur it resolved to null and the
         * null-safe operator swallowed it. The serial landed on the export
         * line and never on the faktur, and `written` counted it anyway.
         */
        $export = app(FakturExporter::class)->export(2026, 8, $this->finance);

        $asing = $this->semua()->where('region_id', $this->jkt->id)->firstOrFail();

        app(NsfpRecorder::class)->record(
            $export, [$asing->nomor => '0400002512345678'], $this->finance,
        );

        $this->assertSame(
            '0400002512345678',
            (string) $this->semua()->find($asing->id)->nsfp,
        );
    }

    public function test_a_serial_already_used_in_another_region_is_refused(): void
    {
        // The guard the recorder's own docblock calls "a discrepancy the tax
        // office finds and we do not" — it could not see the clash it exists
        // to find.
        $export = app(FakturExporter::class)->export(2026, 8, $this->finance);

        [$asing, $rumah] = [
            $this->semua()->where('region_id', $this->jkt->id)->firstOrFail(),
            $this->semua()->where('region_id', $this->sby->id)->firstOrFail(),
        ];

        app(NsfpRecorder::class)->record(
            $export, [$asing->nomor => '0400002512345678'], $this->finance,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/sudah tercatat pada faktur/');

        app(NsfpRecorder::class)->record(
            $export, [$rumah->nomor => '0400002512345678'], $this->finance,
        );
    }

    // --- the screen and the file ---------------------------------------------

    public function test_a_filing_made_from_another_region_is_visible_and_downloadable(): void
    {
        /*
         * The company files one SPT a month, so "has this month been filed"
         * has one answer. Scoped, a filing made from Jakarta's books was
         * absent from the list here — and an empty list honestly reads as
         * "nobody has filed yet", which is how a month gets filed twice.
         */
        $export = app(RegionContext::class)->within(
            $this->jkt,
            fn () => app(FakturExporter::class)->export(2026, 8, $this->finance),
        );

        $this->assertSame((int) $this->jkt->id, (int) $export->region_id);

        $terlihat = FilingScope::entityWide(FakturExport::class)
            ->where('tahun_pajak', 2026)->where('masa_pajak', 8)->get();

        $this->assertTrue($terlihat->contains('id', $export->id));

        // And the file itself — the one thing that has to be producible during
        // an audit — comes back rather than 404ing.
        $this->actingAs($this->finance, 'web')
            ->get(route('faktur-pajak.unduh', $export->id))
            ->assertOk();
    }

    // --- fixtures -------------------------------------------------------------

    private function stockUp(Warehouse $gudang, int $qty): void
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'warehouse_id' => $gudang->id,
            'created_by' => $this->finance->id,
        ]);
        GoodsReceiptLine::factory()->pieces($qty, 60_000)->create([
            'goods_receipt_id' => $receipt->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);
    }

    private function invoiceFor(Warehouse $gudang, int $qty): Invoice
    {
        $company = Company::factory()->creditLimit(500_000_000)->create([
            'payment_terms_days' => 30,
            'npwp' => '01.234.567.8-901.000',
            'nama_wajib_pajak' => 'PT Pembeli Sejahtera',
            'alamat_pajak' => 'Jl. Industri No. 5, Bekasi',
        ]);

        $order = Order::factory()->create([
            'company_id' => $company->id,
            'warehouse_id' => $gudang->id,
            'created_by' => $this->sales->id,
        ]);
        OrderLine::factory()->qty($qty)->create([
            'order_id' => $order->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->submit($order->refresh(), $this->sales);
        $machine->confirm($order->refresh(), $this->approver());
        $machine->awaitPayment($order->refresh(), $this->sales);

        return $order->refresh()->invoice()->firstOrFail();
    }
}
