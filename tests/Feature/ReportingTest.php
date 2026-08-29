<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Access\TeamAssigner;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Ledger;
use App\Domain\Billing\CreditNoteIssuer;
use App\Domain\Billing\CreditNotePoster;
use App\Domain\Billing\CreditNoteType;
use App\Domain\Billing\OutstandingReceivables;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Reporting\LapsedCustomers;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\ReceivablesAgeing;
use App\Domain\Reporting\ReportColumn;
use App\Domain\Reporting\ReportCsv;
use App\Domain\Reporting\RingkasanBulanan;
use App\Domain\Reporting\SalesDimension;
use App\Domain\Reporting\SalesReport;
use App\Domain\Reporting\StockAgeing;
use App\Domain\Stock\InventoryValuation;
use App\Domain\Stock\StockLedger;
use App\Models\Company;
use App\Models\CreditNoteLine;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Reports.
 *
 * A report is only worth anything if it agrees with the thing it claims to
 * describe, so most of these tests are reconciliations rather than
 * calculations: sales against the ledger's Penjualan, ageing against Piutang
 * Usaha. A figure that is internally consistent and disagrees with the
 * accounts is worse than no figure, because somebody will quote it.
 */
class ReportingTest extends TestCase
{
    use RefreshDatabase;

    private const SKU_A = 'YH-RP-1';

    private const SKU_B = 'OS-RP-2';

    private Warehouse $gudang;

    private User $sales;

    private User $finance;

    private User $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->create();
        $this->sales = User::factory()->sales()->create(['region_id' => $this->currentRegion()->id]);
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->warehouse = User::factory()->role(Role::Warehouse)->create();

        Product::factory()->create([
            'kode' => self::SKU_A, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS',
            'merk' => 'YUHOLI', 'kategori' => 'SUSPENSION PART', 'description' => 'Shock depan',
        ]);
        Product::factory()->create([
            'kode' => self::SKU_B, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS',
            'merk' => 'OSBORN', 'kategori' => 'BEARING PART', 'description' => 'Bearing roda',
        ]);

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subYears(2)->toDateString(),
        ]);

        foreach ([self::SKU_A, self::SKU_B] as $sku) {
            PriceListItem::factory()->create([
                'version_id' => $version->id, 'kode' => $sku, 'harga' => 100_000,
            ]);
        }

        $this->stockUp(self::SKU_A, 1_000, 60_000);
        $this->stockUp(self::SKU_B, 1_000, 40_000);
    }

    // ------------------------------------------------------------ sales

    public function test_sales_ties_to_penjualan_in_the_ledger(): void
    {
        /*
         * The property that makes this report usable in an argument. The
         * ledger credits Penjualan when an invoice is issued, so the report
         * counts the same event — and if the two ever disagree, one of them is
         * a bug rather than a difference of opinion.
         */
        $this->travelTo('2026-05-10 09:00:00');
        $this->shippedOrder($this->customer('CV Satu'), self::SKU_A, 10);
        $this->shippedOrder($this->customer('CV Dua'), self::SKU_B, 25);

        $report = app(SalesReport::class)
            ->build(Period::month('2026-05'), SalesDimension::Pelanggan);

        $this->assertSame(
            app(Ledger::class)->balanceOf(AccountCode::PENJUALAN),
            $report->totals['penjualan'],
        );
    }

    public function test_margin_uses_the_cost_frozen_when_the_goods_left(): void
    {
        // 10 × 100,000 sold; 10 × 60,000 cost, frozen at shipment. Not
        // recomputed from today's average, which a later purchase would move.
        $this->travelTo('2026-05-10 09:00:00');
        $this->shippedOrder($this->customer('CV Satu'), self::SKU_A, 10);

        // A later, dearer purchase. It must not touch May's margin.
        $this->travelTo('2026-06-01 09:00:00');
        $this->stockUp(self::SKU_A, 500, 90_000);

        $row = app(SalesReport::class)
            ->build(Period::month('2026-05'), SalesDimension::Pelanggan)
            ->rows[0];

        $this->assertSame(1_000_000, $row['penjualan']);
        $this->assertSame(600_000, $row['hpp']);
        $this->assertSame(400_000, $row['margin']);
        $this->assertSame(40.0, $row['margin_persen']);
    }

    public function test_cost_follows_its_own_sale_even_when_shipping_slips_a_month(): void
    {
        /*
         * The decision at the heart of this report. An order is invoiced at
         * awaiting_payment and ships later; pairing a month's invoices with
         * that month's shipments would put one sale's revenue beside another
         * sale's cost. Each invoice is matched to its own order's shipment
         * instead, whenever that happened.
         */
        $this->travelTo('2026-05-28 09:00:00');
        $order = $this->invoicedOrder($this->customer('CV Satu'), self::SKU_A, 10);

        // Shipped in June against a May invoice.
        $this->travelTo('2026-06-03 09:00:00');
        $machine = app(OrderStateMachine::class);
        $machine->markPaid($order->refresh(), ['sumber' => 'test']);
        $machine->ship($order->refresh(), $this->warehouse);

        $may = app(SalesReport::class)
            ->build(Period::month('2026-05'), SalesDimension::Pelanggan);

        $this->assertSame(1_000_000, $may->totals['penjualan']);
        $this->assertSame(600_000, $may->totals['hpp'], 'June shipment belongs to the May sale.');

        $june = app(SalesReport::class)
            ->build(Period::month('2026-06'), SalesDimension::Pelanggan);

        $this->assertSame(0, $june->totals['penjualan']);
        $this->assertSame(0, $june->totals['hpp'], 'And must not be counted again in June.');
    }

    public function test_an_invoice_whose_goods_have_not_shipped_is_flagged_not_hidden(): void
    {
        /*
         * Its margin is overstated, because there is no cost yet. Saying so is
         * the difference between a report that is wrong and one that is
         * honest about what it cannot know.
         */
        $this->travelTo('2026-05-10 09:00:00');
        $this->invoicedOrder($this->customer('CV Satu'), self::SKU_A, 10);

        $report = app(SalesReport::class)
            ->build(Period::month('2026-05'), SalesDimension::Pelanggan);

        $this->assertSame(1_000_000, $report->totals['penjualan']);
        $this->assertSame(0, $report->totals['hpp']);
        $this->assertNotSame([], $report->catatan);
        $this->assertStringContainsString('belum dikirim', $report->catatan[0]);
    }

    public function test_a_credit_note_reduces_what_was_sold(): void
    {
        $this->travelTo('2026-05-10 09:00:00');
        $company = $this->customer('CV Satu');
        $order = $this->shippedOrder($company, self::SKU_A, 10);

        $this->creditFor($order, 4);

        $report = app(SalesReport::class)
            ->build(Period::month('2026-05'), SalesDimension::Pelanggan);

        // 10 sold, 4 returned: 600,000 net of the 1,000,000 invoiced.
        $this->assertSame(600_000, $report->totals['penjualan']);
        $this->assertSame(
            app(Ledger::class)->balanceOf(AccountCode::PENJUALAN),
            $report->totals['penjualan'],
        );
    }

    public function test_a_voided_invoice_is_not_revenue(): void
    {
        // It was never owed and never sold. Counting it would overstate the
        // month and disagree with Penjualan, which the void reversed.
        $this->travelTo('2026-05-10 09:00:00');
        $order = $this->invoicedOrder($this->customer('CV Satu'), self::SKU_A, 10);

        $order->refresh()->invoice->forceFill(['status' => Invoice::STATUS_VOID])->save();

        $report = app(SalesReport::class)
            ->build(Period::month('2026-05'), SalesDimension::Pelanggan);

        $this->assertTrue($report->isEmpty());
    }

    public function test_a_draft_credit_note_does_not_reduce_sales(): void
    {
        /*
         * A draft is somebody's intention. Letting one lower a revenue figure
         * is how a return that was never agreed ends up in a report the owner
         * acts on.
         */
        $this->travelTo('2026-05-10 09:00:00');
        $order = $this->shippedOrder($this->customer('CV Satu'), self::SKU_A, 10);

        $note = app(CreditNoteIssuer::class)->draft(
            $order->refresh()->invoice,
            CreditNoteType::ReturBarang,
            $this->sales,
            'Belum disetujui',
            warehouseId: $this->gudang->id,
        );
        CreditNoteLine::factory()->create([
            'credit_note_id' => $note->id,
            'order_line_id' => $order->lines()->first()->id,
            'sku' => self::SKU_A,
            'urutan' => 1,
            'qty_base' => 4,
        ]);

        $report = app(SalesReport::class)
            ->build(Period::month('2026-05'), SalesDimension::Pelanggan);

        $this->assertSame(1_000_000, $report->totals['penjualan']);
        $this->assertSame(
            app(Ledger::class)->balanceOf(AccountCode::PENJUALAN),
            $report->totals['penjualan'],
        );
    }

    public function test_the_same_sales_split_four_ways_agree_on_the_total(): void
    {
        /*
         * Customer, brand, category and month are one query with different
         * grouping. If they disagree, the grouping is dropping rows — which is
         * invisible on any single view of it.
         */
        $this->travelTo('2026-05-10 09:00:00');
        $this->shippedOrder($this->customer('CV Satu'), self::SKU_A, 10);
        $this->shippedOrder($this->customer('CV Dua'), self::SKU_B, 25);

        $report = app(SalesReport::class);
        $period = Period::month('2026-05');

        $totals = array_map(
            fn (SalesDimension $d) => $report->build($period, $d)->totals['penjualan'],
            SalesDimension::cases(),
        );

        $this->assertCount(1, array_unique($totals), 'Every grouping must sum to the same sales.');
    }

    public function test_sales_by_brand_attributes_to_the_right_brand(): void
    {
        $this->travelTo('2026-05-10 09:00:00');
        $this->shippedOrder($this->customer('CV Satu'), self::SKU_A, 10);
        $this->shippedOrder($this->customer('CV Dua'), self::SKU_B, 25);

        $rows = collect(app(SalesReport::class)
            ->build(Period::month('2026-05'), SalesDimension::Merk)->rows)
            ->keyBy('dimensi');

        $this->assertSame(2_500_000, $rows['OSBORN']['penjualan']);
        $this->assertSame(1_000_000, $rows['YUHOLI']['penjualan']);
        // Biggest first — the report is ordered by what matters.
        $this->assertSame('OSBORN', app(SalesReport::class)
            ->build(Period::month('2026-05'), SalesDimension::Merk)->rows[0]['dimensi']);
    }

    public function test_cost_columns_disappear_for_whoever_may_not_see_cost(): void
    {
        // Cost plus selling price is margin, which is why Sales are kept away
        // from it everywhere else in the system.
        $this->travelTo('2026-05-10 09:00:00');
        $this->shippedOrder($this->customer('CV Satu'), self::SKU_A, 10);

        $report = app(SalesReport::class)
            ->build(Period::month('2026-05'), SalesDimension::Pelanggan, withCost: false);

        $labels = array_map(fn ($c) => $c->label, $report->visibleColumns());

        $this->assertContains('Penjualan', $labels);
        $this->assertNotContains('HPP', $labels);
        $this->assertNotContains('Margin', $labels);
        $this->assertNull($report->rows[0]['hpp']);
    }

    public function test_a_period_outside_the_trading_returns_nothing_rather_than_failing(): void
    {
        $report = app(SalesReport::class)
            ->build(Period::month('2020-01'), SalesDimension::Pelanggan);

        $this->assertTrue($report->isEmpty());
        $this->assertSame(0, $report->totals['penjualan']);
    }

    // ---------------------------------------------------------- ageing

    public function test_ageing_ties_to_piutang_usaha(): void
    {
        $this->travelTo('2026-05-10 09:00:00');
        $this->invoicedOrder($this->customer('CV Satu'), self::SKU_A, 10);
        $this->invoicedOrder($this->customer('CV Dua'), self::SKU_B, 25);

        $this->travelTo('2026-08-17 09:00:00');

        $report = app(ReceivablesAgeing::class)->build();

        $this->assertSame(
            app(Ledger::class)->balanceOf(AccountCode::PIUTANG_USAHA),
            $report->totals['total'],
        );
        // No discrepancy note means the self-check agreed.
        $this->assertSame([], $report->catatan);
    }

    public function test_debt_lands_in_the_bucket_its_age_puts_it_in(): void
    {
        $this->travelTo('2026-05-10 09:00:00');
        $company = $this->customer('CV Satu');
        $this->invoicedOrder($company, self::SKU_A, 10);

        // Terms are 30 days, so a 10 May invoice is due 9 June. On 20 July it
        // is 41 days late — the 31–60 bucket.
        $this->travelTo('2026-07-20 09:00:00');

        $row = app(ReceivablesAgeing::class)->build()->rows[0];

        $this->assertSame(0, $row['belum_jatuh_tempo']);
        $this->assertSame(0, $row['b1']);
        $this->assertSame(1_110_000, $row['b2']);
    }

    public function test_an_invoice_not_yet_due_is_not_called_late(): void
    {
        $this->travelTo('2026-08-10 09:00:00');
        $this->invoicedOrder($this->customer('CV Satu'), self::SKU_A, 10);

        $this->travelTo('2026-08-17 09:00:00');

        $row = app(ReceivablesAgeing::class)->build()->rows[0];

        $this->assertSame(1_110_000, $row['belum_jatuh_tempo']);
        $this->assertSame(0, $row['b1'] + $row['b2'] + $row['b3'] + $row['b4']);
    }

    public function test_a_payment_nobody_has_matched_still_reduces_the_debt(): void
    {
        /*
         * Money in the bank is money the customer no longer owes, even while
         * finance works out which invoice it was for. Leaving it out would
         * make the report disagree with the balance sheet and chase somebody
         * who has already paid.
         */
        $this->travelTo('2026-05-10 09:00:00');
        $company = $this->customer('CV Satu');
        $this->invoicedOrder($company, self::SKU_A, 10);

        app(PaymentLedger::class)->recordManualPayment(
            company: $company,
            amountRupiah: 500_000,
            actor: $this->finance,
            catatan: 'Transfer TRF-001',
        );

        $this->travelTo('2026-08-17 09:00:00');

        $report = app(ReceivablesAgeing::class)->build();

        $this->assertSame(-500_000, $report->rows[0]['belum_dicocokkan']);
        $this->assertSame(610_000, $report->rows[0]['total']);
        $this->assertSame(
            app(Ledger::class)->balanceOf(AccountCode::PIUTANG_USAHA),
            $report->totals['total'],
        );
    }

    public function test_a_draft_credit_note_does_not_reduce_the_debt(): void
    {
        // Same reason as on the sales side: a draft is an intention, and a
        // customer whose balance drops on one gets credit they were not given.
        $this->travelTo('2026-05-10 09:00:00');
        $company = $this->customer('CV Satu');
        $order = $this->invoicedOrder($company, self::SKU_A, 10);

        $note = app(CreditNoteIssuer::class)->draft(
            $order->refresh()->invoice,
            CreditNoteType::ReturBarang,
            $this->sales,
            'Belum disetujui',
            warehouseId: $this->gudang->id,
        );
        CreditNoteLine::factory()->create([
            'credit_note_id' => $note->id,
            'order_line_id' => $order->lines()->first()->id,
            'sku' => self::SKU_A,
            'urutan' => 1,
            'qty_base' => 4,
        ]);

        $this->travelTo('2026-08-17 09:00:00');

        $report = app(ReceivablesAgeing::class)->build();

        $this->assertSame(1_110_000, $report->totals['total']);
        $this->assertSame([], $report->catatan);
    }

    public function test_the_ageing_shouts_when_it_disagrees_with_the_control_account(): void
    {
        /*
         * The tripwire. These two figures are derived from the same tables, so
         * they can only disagree if one of them is wrong — and an ageing
         * report quietly disagreeing with the balance sheet is one finance
         * stops believing at exactly the moment it matters.
         *
         * Forced here with a stubbed control total, because the only way to
         * produce the disagreement honestly would be to introduce the bug.
         */
        $this->travelTo('2026-05-10 09:00:00');
        $this->invoicedOrder($this->customer('CV Satu'), self::SKU_A, 10);

        $this->travelTo('2026-08-17 09:00:00');

        $wrong = \Mockery::mock(OutstandingReceivables::class)->makePartial();
        $wrong->shouldReceive('total')->andReturn(999_999);

        $report = (new ReceivablesAgeing($wrong))->build();

        $this->assertNotSame([], $report->catatan);
        $this->assertStringContainsString('PERIKSA', $report->catatan[0]);
        $this->assertStringContainsString('tidak sama dengan Piutang Usaha', $report->catatan[0]);
    }

    public function test_a_customer_who_owes_nothing_is_not_listed(): void
    {
        $this->travelTo('2026-05-10 09:00:00');
        $this->customer('CV Tidak Punya Utang');

        $this->assertTrue(app(ReceivablesAgeing::class)->build()->isEmpty());
    }

    // ------------------------------------------------------- lapsed

    public function test_a_customer_who_broke_their_rhythm_is_found(): void
    {
        /*
         * The report that finds money nobody is looking for. This customer
         * ordered every 30 days and then stopped; nothing else in the system
         * would ever mention it.
         */
        $company = $this->customer('CV Rutin');

        foreach (['2026-01-05', '2026-02-04', '2026-03-06', '2026-04-05'] as $date) {
            $this->travelTo($date.' 09:00:00');
            $this->paidOrder($company, self::SKU_A, 10);
        }

        $this->travelTo('2026-08-17 09:00:00');

        $rows = app(LapsedCustomers::class)->build()->rows;

        $this->assertCount(1, $rows);
        $this->assertSame('CV Rutin', $rows[0]['dimensi']);
        $this->assertSame(4, $rows[0]['order']);
        $this->assertEqualsWithDelta(30, $rows[0]['biasanya'], 2);
        $this->assertGreaterThan(100, $rows[0]['diam']);
    }

    public function test_a_customer_still_within_their_own_rhythm_is_left_alone(): void
    {
        /*
         * The reason the threshold is each customer's own interval rather than
         * one number. This one orders quarterly and is not late; a flat
         * sixty-day rule would call them lapsed and waste the report's
         * credibility.
         */
        $company = $this->customer('CV Kuartalan');

        foreach (['2025-08-01', '2025-11-01', '2026-02-01', '2026-05-01'] as $date) {
            $this->travelTo($date.' 09:00:00');
            $this->paidOrder($company, self::SKU_A, 10);
        }

        /*
         * Silent 78 days. A flat sixty-day rule would call them lapsed and
         * send somebody to chase a customer who is not even late; against
         * their own ~92 day rhythm they are simply between orders.
         */
        $this->travelTo('2026-07-18 09:00:00');

        $this->assertTrue(app(LapsedCustomers::class)->build()->isEmpty());
    }

    public function test_one_long_gap_does_not_hide_a_customer_who_has_stopped(): void
    {
        /*
         * Why the interval is a median and not an average. This customer
         * ordered monthly, disappeared for most of a year once, came back, and
         * has now stopped again. The mean of their gaps is over three months,
         * which would call the current silence normal. The middle gap is a
         * month, which calls it what it is.
         */
        $company = $this->customer('CV Pernah Hilang');

        foreach (['2025-01-05', '2025-02-04', '2025-03-06', '2026-01-10', '2026-02-09'] as $date) {
            $this->travelTo($date.' 09:00:00');
            $this->paidOrder($company, self::SKU_A, 10);
        }

        // 100 days of silence: more than twice their median 30-day rhythm,
        // less than twice a mean dragged out by the missing year.
        $this->travelTo('2026-05-20 09:00:00');

        $rows = app(LapsedCustomers::class)->build()->rows;

        $this->assertCount(1, $rows);
        $this->assertSame('CV Pernah Hilang', $rows[0]['dimensi']);
        $this->assertLessThan(40, $rows[0]['biasanya'], 'The median ignores the outlier gap.');
    }

    public function test_a_customer_with_too_little_history_is_not_guessed_at(): void
    {
        // Two orders is one interval, and one interval is an anecdote.
        $company = $this->customer('CV Baru');

        foreach (['2026-01-05', '2026-02-04'] as $date) {
            $this->travelTo($date.' 09:00:00');
            $this->paidOrder($company, self::SKU_A, 10);
        }

        $this->travelTo('2026-08-17 09:00:00');

        $this->assertTrue(app(LapsedCustomers::class)->build()->isEmpty());
    }

    public function test_lapsed_customers_are_ranked_by_what_they_were_worth(): void
    {
        // The order is the recommendation: ring the top one first.
        $small = $this->customer('CV Kecil');
        $big = $this->customer('CV Besar');

        foreach (['2026-01-05', '2026-02-04', '2026-03-06'] as $date) {
            $this->travelTo($date.' 09:00:00');
            $this->paidOrder($small, self::SKU_A, 5);
            $this->paidOrder($big, self::SKU_A, 100);
        }

        $this->travelTo('2026-08-17 09:00:00');

        $rows = app(LapsedCustomers::class)->build()->rows;

        $this->assertSame('CV Besar', $rows[0]['dimensi']);
        $this->assertGreaterThan($rows[1]['per_bulan'], $rows[0]['per_bulan']);
    }

    // -------------------------------------------------------- stock

    public function test_stock_that_has_never_sold_comes_first(): void
    {
        /*
         * The top of this list is where the cash is stuck. A part that has
         * never moved is a buying mistake; one that moves slowly is a stocking
         * decision, and they are ordered so the first is read first.
         */
        $this->travelTo('2026-05-10 09:00:00');
        $this->shippedOrder($this->customer('CV Satu'), self::SKU_A, 100);

        $this->travelTo('2026-08-17 09:00:00');

        $rows = collect(app(StockAgeing::class)->build()->rows);

        $this->assertSame(self::SKU_B, $rows[0]['dimensi'], 'Never sold sorts to the top.');
        $this->assertNull($rows[0]['cover_bulan']);
        $this->assertNull($rows[0]['terakhir_keluar']);

        $moved = $rows->firstWhere('dimensi', self::SKU_A);

        $this->assertSame(100, $moved['terjual']);
        $this->assertNotNull($moved['cover_bulan']);
        $this->assertSame('2026-05-10', $moved['terakhir_keluar']);
    }

    public function test_stock_value_matches_the_moving_average(): void
    {
        // 1,000 at 60,000 and 1,000 at 40,000, none sold.
        $report = app(StockAgeing::class)->build();

        $this->assertSame(100_000_000, $report->totals['nilai']);
        $this->assertSame(
            app(InventoryValuation::class)->totalValue(),
            $report->totals['nilai'],
        );
    }

    public function test_stock_value_holds_on_an_average_that_does_not_divide(): void
    {
        /*
         * The reason value is apportioned from the (quantity, value) pair
         * rather than multiplied by a unit cost. Three units at 7,777 is
         * 23,331; a rounded unit cost of 7,777 happens to work, but the same
         * arithmetic on a pair that does not divide evenly loses rupiah on
         * every SKU and the report stops tying to inventory value.
         */
        $sku = 'YH-RP-ODD';
        Product::factory()->create([
            'kode' => $sku, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS',
            'merk' => 'STAVO', 'kategori' => 'ELECTRIC PART', 'description' => 'Ganjil',
        ]);

        $this->stockUp($sku, 3, 7_777);
        $this->stockUp($sku, 4, 3_333);

        $report = app(StockAgeing::class)->build();

        $this->assertSame(
            app(InventoryValuation::class)->totalValue(),
            $report->totals['nilai'],
        );

        $row = collect($report->rows)->firstWhere('dimensi', $sku);

        $this->assertSame(7, $row['qty']);
        $this->assertSame(3 * 7_777 + 4 * 3_333, $row['nilai']);
    }

    public function test_a_transfer_does_not_make_dead_stock_look_alive(): void
    {
        // Moving a carton across the yard is not a sale, and counting it would
        // reset the clock on exactly the stock this report exists to find.
        $cabang = Warehouse::factory()->create(['nama' => 'Gudang Cabang']);

        $this->travelTo('2026-08-17 09:00:00');

        app(StockLedger::class)->transfer(
            sku: self::SKU_B,
            fromWarehouseId: $this->gudang->id,
            toWarehouseId: $cabang->id,
            qtyBase: 100,
            actor: $this->warehouse,
        );

        $row = collect(app(StockAgeing::class)->build()->rows)
            ->firstWhere('dimensi', self::SKU_B);

        $this->assertNull($row['terakhir_keluar']);
        $this->assertSame(0, $row['terjual']);
        // And the quantity is unchanged, because it is still ours.
        $this->assertSame(1_000, $row['qty']);
    }

    // ---------------------------------------------------------------- csv

    public function test_the_export_gives_a_spreadsheet_numbers_it_can_add_up(): void
    {
        /*
         * The point of a separate CSV formatter. `Rp 1.250.000` is text to a
         * spreadsheet, and the owner's next move after reading a report is
         * always to sort it differently somewhere else.
         */
        $this->travelTo('2026-05-10 09:00:00');
        $this->shippedOrder($this->customer('CV Satu'), self::SKU_A, 10);

        $csv = app(ReportCsv::class)->write(
            app(SalesReport::class)->build(Period::month('2026-05'), SalesDimension::Pelanggan)
        );

        $this->assertStringContainsString('1000000', $csv);
        $this->assertStringNotContainsString('Rp 1.000.000', $csv);
        // Semicolons, because Indonesian Excel reads comma-delimited as one
        // column, and a BOM so accented names survive.
        $this->assertStringStartsWith("\u{FEFF}", $csv);
        $this->assertStringContainsString('"Penjualan per pelanggan"', $csv);
        $this->assertStringContainsString('"Mei 2026"', $csv);
        $this->assertStringContainsString('"TOTAL"', $csv);
    }

    public function test_the_export_leaves_out_columns_the_reader_may_not_see(): void
    {
        $this->travelTo('2026-05-10 09:00:00');
        $this->shippedOrder($this->customer('CV Satu'), self::SKU_A, 10);

        $csv = app(ReportCsv::class)->write(
            app(SalesReport::class)
                ->build(Period::month('2026-05'), SalesDimension::Pelanggan, withCost: false)
        );

        $this->assertStringNotContainsString('HPP', $csv);
        $this->assertStringNotContainsString('600000', $csv);
    }

    // ------------------------------------------------------------- formatting

    public function test_a_date_reads_the_same_whichever_query_produced_it(): void
    {
        /*
         * Reports build rows straight off query results, so a date arrives
         * sometimes as a Carbon and sometimes as the string a `toDateString()`
         * left behind. On screen the stock report printed `2026-08-17` in one
         * column while the period above it read `17/08/2026` — both the right
         * day, and it still reads as a bug.
         */
        $column = ReportColumn::date('tanggal', 'Tanggal');

        $this->assertSame('17/08/2026', $column->format('2026-08-17'));
        $this->assertSame('17/08/2026', $column->format(Carbon::parse('2026-08-17 14:30:00')));
        $this->assertSame('—', $column->format(null));
    }

    public function test_a_snapshot_report_is_labelled_as_a_day_not_a_range(): void
    {
        // Ageing is a position on a date. `18 Agt 2026 – 18 Agt 2026` reads
        // like a range somebody got wrong.
        $period = Period::asOf('2026-08-18');

        $this->assertSame('Per 18 Agt 2026', $period->label);
        // Still the whole day underneath, so filtering is unchanged.
        $this->assertSame('2026-08-18 00:00:00', $period->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-18 23:59:59', $period->to->format('Y-m-d H:i:s'));
    }

    public function test_the_export_dates_stay_sortable(): void
    {
        // ISO in the CSV, deliberately unlike the screen: a spreadsheet sorts
        // `2026-08-17` as a date and `17/08/2026` as text.
        $column = ReportColumn::date('tanggal', 'Tanggal');

        $this->assertSame('2026-08-17', $column->forCsv('2026-08-17'));
        $this->assertSame('2026-08-17', $column->forCsv(Carbon::parse('2026-08-17 14:30:00')));
        $this->assertSame('', $column->forCsv(null));
    }

    // --- helpers ------------------------------------------------------------

    // --- ringkasan bulanan --------------------------------------------------

    /**
     * The summary must agree with the reports it summarises — that is its
     * whole design (it composes them rather than querying) — and this test
     * pins the composition: sales from the invoiced month, cash from the
     * payment ledger, the receivable position from today.
     */
    public function test_the_monthly_summary_composes_the_reports_it_summarises(): void
    {
        $a = $this->customer('Bengkel Ringkasan A');
        $b = $this->customer('Toko Ringkasan B');

        // A: invoiced, paid through the ledger, and shipped — so it has cost.
        $orderA = $this->paidOrder($a, self::SKU_A, 100);
        app(OrderStateMachine::class)->ship($orderA->refresh(), $this->warehouse);

        // B: invoiced and nothing else — the open receivable.
        $orderB = $this->invoicedOrder($b, self::SKU_B, 50);

        $r = app(RingkasanBulanan::class)
            ->build(Period::month(now()->format('Y-m')));

        // Flow: both invoices' lines, at the 100k list price.
        $this->assertSame(2, $r->faktur);
        $this->assertSame(15_000_000, $r->penjualan);

        // Cost exists only for the shipped order; the caveat owns the rest.
        $this->assertSame(100 * 60_000, $r->hpp);
        $this->assertSame(15_000_000 - 6_000_000, $r->margin);
        $this->assertNotEmpty($r->catatan);

        // Cash is the payment ledger, gross of PPN — a different measure
        // from penjualan on purpose, because it is a different question.
        $this->assertSame((int) $orderA->refresh()->invoice->total_rupiah, $r->uangMasuk);

        // Position: what is still owed, sitting in the not-yet-due band.
        $totalB = (int) $orderB->refresh()->invoice->total_rupiah;
        $this->assertSame($totalB, $r->piutang);
        $this->assertContains($totalB, array_column($r->umurPiutang, 'nilai'));

        // The buckets are signed so they add up to the balance beside them —
        // the whole reason the summary reads bucketTotals, not the chart.
        $this->assertSame($r->piutang, array_sum(array_column($r->umurPiutang, 'nilai')));

        // Who and what mattered.
        $this->assertSame('Bengkel Ringkasan A', $r->topPelanggan[0]['dimensi']);
        $this->assertSame(10_000_000, $r->topPelanggan[0]['penjualan']);
        $this->assertSame(
            ['YUHOLI' => 10_000_000, 'OSBORN' => 5_000_000],
            array_column($r->topMerk, 'penjualan', 'dimensi'),
        );
    }

    public function test_the_summary_separates_the_months_flow_from_todays_position(): void
    {
        $b = $this->customer('Toko Posisi');
        $this->invoicedOrder($b, self::SKU_B, 10);

        // Last month saw none of this month's trade…
        $lalu = app(RingkasanBulanan::class)
            ->build(Period::month(now()->subMonthNoOverflow()->format('Y-m')));

        $this->assertSame(0, $lalu->penjualan);
        $this->assertSame(0, $lalu->uangMasuk);

        // …but the receivable is a position, and the position is today's.
        $this->assertGreaterThan(0, $lalu->piutang);
    }

    public function test_only_the_owner_opens_the_monthly_summary(): void
    {
        $this->actingAs(User::factory()->owner()->create(), 'web')
            ->get('/admin/laporan/ringkasan')->assertOk();

        // Finance sees money and Sales sees sales — this page is the one
        // place margin, cash and every debt sit together, so it is the
        // owner's alone.
        $this->actingAs($this->finance, 'web')
            ->get('/admin/laporan/ringkasan')->assertForbidden();
    }

    private function customer(string $nama): Company
    {
        $company = Company::factory()->creditLimit(5_000_000_000)->create([
            'nama' => $nama,
            'payment_terms_days' => 30,
            'status' => Company::STATUS_ACTIVE,
        ]);

        // Returs are filed by the sales who holds the store.
        app(TeamAssigner::class)->assignSales(
            $company,
            $this->sales,
            User::factory()->owner()->create(),
        );

        return $company;
    }

    /**
     * Invoiced and settled — a customer in good standing, free to order again.
     *
     * The payment is real rather than a status flip, because the credit check
     * reads the receivable ledger: a customer with an unpaid overdue invoice
     * is refused their next order, which is correct behaviour and would
     * otherwise stop these histories being built at all.
     */
    private function paidOrder(Company $company, string $sku, int $qty): Order
    {
        $order = $this->invoicedOrder($company, $sku, $qty);

        app(PaymentLedger::class)->recordManualPayment(
            company: $company,
            amountRupiah: (int) $order->refresh()->invoice->total_rupiah,
            actor: $this->finance,
            invoice: $order->invoice,
        );

        return $order->refresh();
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
