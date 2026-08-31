<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Access\TeamAssigner;
use App\Domain\Billing\CreditNoteIssuer;
use App\Domain\Billing\CreditNotePoster;
use App\Domain\Billing\CreditNoteType;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Reporting\KpiReport;
use App\Domain\Reporting\KpiSubjek;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\SalesDimension;
use App\Domain\Reporting\SalesReport;
use App\Models\Company;
use App\Models\CreditNoteLine;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\SalesTarget;
use App\Models\StoreVisit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three sheets the testers asked for: omset per sales, what is selling,
 * and how each shop and each seat is actually doing.
 *
 * The point of most of these tests is that a KPI is only worth having if it
 * can say something bad. A coverage figure that silently omits the seat with
 * no sales, or a top-seller list ranked on rupiah rather than units, both
 * produce a sheet where nothing ever looks wrong — which is the failure mode
 * worth writing tests against.
 */
class KpiReportTest extends TestCase
{
    use RefreshDatabase;

    private const SKU_MURAH = 'OS-BR-1';   // cheap, sells in volume

    private const SKU_MAHAL = 'ST-AL-1';   // dear, sells rarely

    private Warehouse $gudang;

    private User $sales;

    private User $salesLain;

    private User $finance;

    private User $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15 09:00:00');

        $this->gudang = Warehouse::factory()->create();
        $this->sales = User::factory()->sales()->create([
            'name' => 'Andi', 'region_id' => $this->currentRegion()->id,
        ]);
        $this->salesLain = User::factory()->sales()->create([
            'name' => 'Budi', 'region_id' => $this->currentRegion()->id,
        ]);
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->warehouse = User::factory()->role(Role::Warehouse)->create();

        Product::factory()->create([
            'kode' => self::SKU_MURAH, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS',
            'merk' => 'OSBORN', 'kategori' => 'BEARING PART', 'description' => 'Bearing roda',
        ]);
        Product::factory()->create([
            'kode' => self::SKU_MAHAL, 'qty_per_ctn' => 1, 'satuan_dasar' => 'PCS',
            'merk' => 'STAVO', 'kategori' => 'ELECTRIC PART', 'description' => 'Alternator 70A',
        ]);

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subYears(2)->toDateString(),
        ]);
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => self::SKU_MURAH, 'harga' => 50_000,
        ]);
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => self::SKU_MAHAL, 'harga' => 2_000_000,
        ]);

        $this->stockUp(self::SKU_MURAH, 1_000, 30_000);
        $this->stockUp(self::SKU_MAHAL, 100, 1_200_000);
    }

    // ------------------------------------------------- omset per sales seat

    public function test_omset_is_booked_to_the_seat_that_holds_the_customer(): void
    {
        $this->travelTo('2026-05-10 09:00:00');

        $this->shippedOrder($this->customer('Toko A', $this->sales), self::SKU_MURAH, 20);
        $this->shippedOrder($this->customer('Toko B', $this->salesLain), self::SKU_MURAH, 4);

        $rows = collect(app(SalesReport::class)
            ->build(Period::month('2026-05'), SalesDimension::Sales)
            ->rows)
            ->keyBy('dimensi');

        $this->assertSame(1_000_000, $rows['Andi']['penjualan']);
        $this->assertSame(200_000, $rows['Budi']['penjualan']);
    }

    public function test_a_customer_with_no_sales_seat_is_named_not_dropped(): void
    {
        // Losing this row would make the report's total disagree with the
        // ledger, which is the one thing a sales report may never do.
        $this->travelTo('2026-05-10 09:00:00');

        $yatim = Company::factory()->creditLimit(5_000_000_000)->create([
            'nama' => 'Toko Tanpa Sales',
            'payment_terms_days' => 30,
            'status' => Company::STATUS_ACTIVE,
        ]);
        $this->shippedOrder($yatim, self::SKU_MURAH, 10);

        $report = app(SalesReport::class)->build(Period::month('2026-05'), SalesDimension::Sales);

        $this->assertSame('Belum ada sales', $report->rows[0]['dimensi']);
        $this->assertSame(500_000, $report->totals['penjualan']);
    }

    // ------------------------------------------------------- paling laku

    public function test_the_best_seller_is_ranked_by_units_not_by_rupiah(): void
    {
        /*
         * The whole reason this dimension exists. One alternator at 2 juta
         * outsells forty bearings at 50 ribu on value — and restocking on
         * that answer empties the bearing shelf.
         */
        $this->travelTo('2026-05-10 09:00:00');
        $toko = $this->customer('Toko A', $this->sales);

        $this->shippedOrder($toko, self::SKU_MURAH, 40);
        $this->shippedOrder($toko, self::SKU_MAHAL, 2);

        $report = app(SalesReport::class)->build(Period::month('2026-05'), SalesDimension::Barang);

        $this->assertStringContainsString(self::SKU_MURAH, $report->rows[0]['dimensi']);
        $this->assertSame(40, $report->rows[0]['unit']);
        $this->assertSame(2_000_000, $report->rows[0]['penjualan']);

        // The dearer item made twice the money and still sits second.
        $this->assertStringContainsString(self::SKU_MAHAL, $report->rows[1]['dimensi']);
        $this->assertSame(4_000_000, $report->rows[1]['penjualan']);
    }

    public function test_a_retur_reduces_both_the_units_and_the_money(): void
    {
        $this->travelTo('2026-05-10 09:00:00');
        $order = $this->shippedOrder($this->customer('Toko A', $this->sales), self::SKU_MURAH, 40);

        $this->creditFor($order, 15);

        $row = app(SalesReport::class)
            ->build(Period::month('2026-05'), SalesDimension::Barang)
            ->rows[0];

        $this->assertSame(25, $row['unit']);
        $this->assertSame(1_250_000, $row['penjualan']);
    }

    // ----------------------------------------------------------- KPI sales

    public function test_a_seat_that_sold_nothing_still_has_a_row_with_its_coverage(): void
    {
        /*
         * The row the sheet exists for. Budi holds two shops and invoiced
         * neither; a report built from invoices alone would leave him out and
         * the team would look fully covered.
         */
        $this->travelTo('2026-05-10 09:00:00');

        $this->shippedOrder($this->customer('Toko A', $this->sales), self::SKU_MURAH, 20);
        $this->customer('Toko C', $this->sales);          // held, never bought
        $this->customer('Toko D', $this->salesLain);
        $this->customer('Toko E', $this->salesLain);

        $rows = collect(app(KpiReport::class)
            ->build(Period::month('2026-05'), KpiSubjek::Sales)
            ->rows)
            ->keyBy('nama');

        $this->assertSame(1_000_000, $rows['Andi']['omset']);
        $this->assertSame(1, $rows['Andi']['toko_aktif']);
        $this->assertSame(2, $rows['Andi']['toko_dipegang']);
        $this->assertSame(50.0, $rows['Andi']['cakupan']);

        $this->assertSame(0, $rows['Budi']['omset']);
        $this->assertSame(2, $rows['Budi']['toko_dipegang']);
        $this->assertSame(0.0, $rows['Budi']['cakupan']);
    }

    public function test_the_target_applies_to_a_whole_month_and_to_nothing_else(): void
    {
        // Half a month of sales against a whole month's target is a number
        // that means nothing, so the column stays empty rather than lying.
        $this->travelTo('2026-05-10 09:00:00');
        $this->shippedOrder($this->customer('Toko A', $this->sales), self::SKU_MURAH, 20);

        SalesTarget::query()->create([
            'user_id' => $this->sales->id,
            'tahun' => 2026, 'bulan' => 5,
            'target_rupiah' => 4_000_000,
            'set_by' => $this->approver()->id,
        ]);

        $bulanPenuh = collect(app(KpiReport::class)
            ->build(Period::month('2026-05'), KpiSubjek::Sales)->rows)->keyBy('nama');

        $this->assertSame(4_000_000, $bulanPenuh['Andi']['target']);
        $this->assertSame(25.0, $bulanPenuh['Andi']['capai']);

        $separuh = collect(app(KpiReport::class)->build(
            Period::between('2026-05-01', '2026-05-15'), KpiSubjek::Sales,
        )->rows)->keyBy('nama');

        $this->assertNull($separuh['Andi']['target']);
        $this->assertNull($separuh['Andi']['capai']);
    }

    public function test_visits_and_overdue_debt_land_on_the_seat(): void
    {
        $this->travelTo('2026-05-10 09:00:00');
        $toko = $this->customer('Toko A', $this->sales);

        // Invoiced on credit and never paid: 30-day terms, so by the time
        // this report is read in July it is overdue.
        $this->invoicedOrder($toko, self::SKU_MURAH, 20);

        StoreVisit::factory()->count(3)->create([
            'sales_user_id' => $this->sales->id,
            'company_id' => $toko->id,
            'visited_at' => '2026-05-12 10:00:00',
            'region_id' => $this->currentRegion()->id,
        ]);

        $this->travelTo('2026-07-01 09:00:00');

        $rows = collect(app(KpiReport::class)
            ->build(Period::month('2026-05'), KpiSubjek::Sales)->rows)->keyBy('nama');

        $this->assertSame(3, $rows['Andi']['kunjungan']);
        $this->assertGreaterThan(0, $rows['Andi']['jatuh_tempo']);
        $this->assertSame(0, $rows['Budi']['jatuh_tempo']);
    }

    // ------------------------------------------------------------ KPI toko

    public function test_a_shop_that_stopped_buying_keeps_its_row_and_its_last_date(): void
    {
        $this->travelTo('2026-05-10 09:00:00');
        $rutin = $this->customer('Toko Rutin', $this->sales);
        $this->shippedOrder($rutin, self::SKU_MURAH, 10);
        $this->shippedOrder($rutin, self::SKU_MAHAL, 1);

        $this->customer('Toko Diam', $this->sales);

        $rows = collect(app(KpiReport::class)
            ->build(Period::month('2026-05'), KpiSubjek::Toko)->rows)->keyBy('nama');

        $this->assertSame(2, $rows['Toko Rutin']['faktur']);
        $this->assertSame(2, $rows['Toko Rutin']['jenis_barang']);
        $this->assertSame('2026-05-10', substr((string) $rows['Toko Rutin']['terakhir'], 0, 10));
        $this->assertSame('Andi', $rows['Toko Rutin']['sales']);

        $this->assertSame(0, $rows['Toko Diam']['omset']);
        $this->assertNull($rows['Toko Diam']['terakhir']);
    }

    // ---------------------------------------------------------- KPI barang

    public function test_an_item_is_measured_by_how_many_shops_take_it(): void
    {
        /*
         * Two items, same units sold. One is spread over three shops, the
         * other is one shop's bulk order — and the second is the risky one to
         * stock deeply. Only the buyer count tells them apart.
         */
        $this->travelTo('2026-05-10 09:00:00');

        foreach (['Toko A', 'Toko B', 'Toko C'] as $nama) {
            $this->shippedOrder($this->customer($nama, $this->sales), self::SKU_MURAH, 10);
        }
        $this->shippedOrder($this->customer('Toko D', $this->sales), self::SKU_MAHAL, 30);

        $rows = collect(app(KpiReport::class)
            ->build(Period::month('2026-05'), KpiSubjek::Barang)->rows)
            ->keyBy(fn (array $r) => substr($r['nama'], 0, strpos($r['nama'], ' ')));

        $this->assertSame(30, $rows[self::SKU_MURAH]['unit']);
        $this->assertSame(3, $rows[self::SKU_MURAH]['toko']);

        $this->assertSame(30, $rows[self::SKU_MAHAL]['unit']);
        $this->assertSame(1, $rows[self::SKU_MAHAL]['toko']);

        // Stock is what is on the shelf now, not at the end of May.
        $this->assertSame(970, $rows[self::SKU_MURAH]['stok']);
    }

    public function test_kpi_omset_agrees_with_the_sales_report_for_the_same_month(): void
    {
        // Two screens, one month, one answer. If these ever drift apart, the
        // person holding both printouts is right to stop trusting either.
        $this->travelTo('2026-05-10 09:00:00');
        $order = $this->shippedOrder($this->customer('Toko A', $this->sales), self::SKU_MURAH, 40);
        $this->creditFor($order, 5);

        $penjualan = app(SalesReport::class)
            ->build(Period::month('2026-05'), SalesDimension::Sales, withCost: false);
        $kpi = app(KpiReport::class)->build(Period::month('2026-05'), KpiSubjek::Sales);

        $this->assertSame($penjualan->totals['penjualan'], $kpi->totals['omset']);
    }

    // --------------------------------------------------------------- helpers

    private function customer(string $nama, User $seat): Company
    {
        $company = Company::factory()->creditLimit(5_000_000_000)->create([
            'nama' => $nama,
            'payment_terms_days' => 30,
            'status' => Company::STATUS_ACTIVE,
        ]);

        app(TeamAssigner::class)->assignSales($company, $seat, $this->approver());

        return $company;
    }

    private function invoicedOrder(Company $company, string $sku, int $qty): Order
    {
        $order = Order::factory()->create([
            'company_id' => $company->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $this->sales->id,
        ]);
        OrderLine::factory()->qty($qty)->create([
            'order_id' => $order->id, 'sku' => $sku, 'urutan' => 1,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->submit($order->refresh(), $this->sales);
        $machine->confirm($order->refresh(), $this->approver());
        $machine->awaitPayment($order->refresh(), $this->sales);

        return $order->refresh();
    }

    private function shippedOrder(Company $company, string $sku, int $qty): Order
    {
        $order = $this->invoicedOrder($company, $sku, $qty);

        $machine = app(OrderStateMachine::class);
        $machine->markPaid($order->refresh(), ['sumber' => 'test']);
        $machine->ship($order->refresh(), $this->warehouse);

        return $order->refresh();
    }

    private function creditFor(Order $order, int $qty): void
    {
        $note = app(CreditNoteIssuer::class)->draft(
            $order->refresh()->invoice,
            CreditNoteType::ReturBarang,
            $this->sales,
            'Barang tidak sesuai',
            warehouseId: $this->gudang->id,
        );

        CreditNoteLine::factory()->create([
            'credit_note_id' => $note->id,
            'order_line_id' => $order->lines()->first()->id,
            'sku' => $order->lines()->first()->sku,
            'urutan' => 1,
            'qty_base' => $qty,
        ]);

        app(CreditNotePoster::class)->post($note->refresh(), $this->warehouse);
    }

    private function stockUp(string $sku, int $qty, int $unitCost): void
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $this->finance->id,
        ]);
        GoodsReceiptLine::factory()->pieces($qty, $unitCost)->create([
            'goods_receipt_id' => $receipt->id, 'sku' => $sku, 'urutan' => 1,
        ]);

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);
    }
}
