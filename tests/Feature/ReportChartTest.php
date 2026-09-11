<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Regions\RegionContext;
use App\Domain\Reporting\LapsedCustomers;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\ReceivablesAgeing;
use App\Domain\Reporting\ReportChart;
use App\Domain\Reporting\ReportColumn;
use App\Domain\Reporting\ReportTable;
use App\Domain\Reporting\SalesDimension;
use App\Domain\Reporting\SalesReport;
use App\Domain\Reporting\StockAgeing;
use App\Filament\Pages\Laporan\Penjualan;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every report chart derives from the table it stands over — same rows, same
 * filters — so the bars can never disagree with the figures beneath them.
 * The one chart with its own query, sales per region, reads across every
 * region's books on purpose and is tested against two of them.
 */
class ReportChartTest extends TestCase
{
    use RefreshDatabase;

    private function table(array $rows, array $columns = [], array $totals = []): ReportTable
    {
        return new ReportTable(
            judul: 'Uji',
            period: Period::month('2026-08'),
            columns: $columns,
            rows: $rows,
            totals: $totals,
        );
    }

    public function test_top_rows_sorts_limits_and_drops_zeroes(): void
    {
        $chart = ReportChart::topRows('Uji', [
            ['dimensi' => 'Kecil', 'nilai' => 100],
            ['dimensi' => 'Nol', 'nilai' => 0],
            ['dimensi' => 'Besar', 'nilai' => 900],
        ], 'dimensi', 'nilai', limit: 2);

        $this->assertSame(['Besar', 'Kecil'], $chart->labels);
        $this->assertSame([900, 100], $chart->values);
        $this->assertFalse($chart->isEmpty());
    }

    public function test_a_chart_with_nothing_positive_reports_itself_empty(): void
    {
        $chart = new ReportChart('Uji', ['A'], [0]);

        $this->assertTrue($chart->isEmpty());
    }

    public function test_sales_chart_keeps_the_calendar_order_by_month(): void
    {
        $table = $this->table([
            ['dimensi' => '2026-06', 'penjualan' => 100],
            ['dimensi' => '2026-07', 'penjualan' => 900],
            ['dimensi' => '2026-08', 'penjualan' => 400],
        ]);

        $chart = app(SalesReport::class)->chart($table, SalesDimension::Bulan);

        // Chronological, not biggest-first: a year read out of order is noise.
        $this->assertSame(['2026-06', '2026-07', '2026-08'], $chart->labels);
        $this->assertSame([100, 900, 400], $chart->values);
    }

    public function test_sales_chart_shows_the_biggest_first_on_other_dimensions(): void
    {
        $table = $this->table([
            ['dimensi' => 'CV Kecil', 'penjualan' => 200],
            ['dimensi' => 'CV Besar', 'penjualan' => 700],
        ]);

        $chart = app(SalesReport::class)->chart($table, SalesDimension::Pelanggan);

        $this->assertSame(['CV Besar', 'CV Kecil'], $chart->labels);
    }

    public function test_ageing_chart_reads_the_totals_row_bucket_by_bucket(): void
    {
        $columns = [
            ReportColumn::text('dimensi', 'Pelanggan'),
            ReportColumn::money('belum_jatuh_tempo', 'Belum jatuh tempo'),
            ReportColumn::money('b1', '1–30 hari'),
            ReportColumn::money('b2', '31–60 hari'),
            ReportColumn::money('b3', '61–90 hari'),
            ReportColumn::money('b4', '> 90 hari'),
            ReportColumn::money('belum_dicocokkan', 'Belum dicocokkan'),
            ReportColumn::money('dijamin_giro', 'Dijamin giro'),
            ReportColumn::money('total', 'Total'),
        ];

        $table = $this->table([], $columns, [
            'belum_jatuh_tempo' => 5_000_000,
            'b1' => 3_000_000, 'b2' => 0, 'b3' => 1_000_000, 'b4' => 250_000,
            'belum_dicocokkan' => -400_000,
            'dijamin_giro' => 750_000,
            'total' => 9_600_000,
        ]);

        $chart = app(ReceivablesAgeing::class)->chart($table);

        $this->assertSame(
            ['Belum jatuh tempo', '1–30 hari', '31–60 hari', '61–90 hari', '> 90 hari', 'Belum dicocokkan', 'Dijamin giro'],
            $chart->labels,
        );
        // The unmatched column is negative in the table (money already in);
        // as a bar it clamps to zero rather than drawing backwards.
        $this->assertSame([5_000_000, 3_000_000, 0, 1_000_000, 250_000, 0, 750_000], $chart->values);
        // The grand total is not a bar — it would dwarf every real bucket.
        $this->assertNotContains('Total', $chart->labels);
    }

    public function test_lapsed_chart_ranks_by_the_monthly_value_going_quiet(): void
    {
        $table = $this->table([
            ['dimensi' => 'Bengkel Kecil', 'per_bulan' => 500_000, 'seumur_hidup' => 90_000_000],
            ['dimensi' => 'Bengkel Rutin', 'per_bulan' => 4_000_000, 'seumur_hidup' => 20_000_000],
        ]);

        $chart = app(LapsedCustomers::class)->chart($table);

        // Monthly value, not lifetime: the recurring loss decides who to ring.
        $this->assertSame(['Bengkel Rutin', 'Bengkel Kecil'], $chart->labels);
        $this->assertSame([4_000_000, 500_000], $chart->values);
    }

    public function test_stock_chart_folds_rows_into_idle_bands(): void
    {
        $table = $this->table([
            ['dimensi' => 'A', 'nilai' => 1_000_000, 'diam' => null],
            ['dimensi' => 'B', 'nilai' => 2_000_000, 'diam' => 200],
            ['dimensi' => 'C', 'nilai' => 400_000, 'diam' => 120],
            ['dimensi' => 'D', 'nilai' => 300_000, 'diam' => 45],
            ['dimensi' => 'E', 'nilai' => 50_000, 'diam' => 3],
            ['dimensi' => 'F', 'nilai' => 111_000, 'diam' => 181],
        ]);

        $chart = app(StockAgeing::class)->chart($table);

        $this->assertSame(
            ['Belum pernah keluar', '> 180 hari', '91–180 hari', '31–90 hari', '≤ 30 hari'],
            $chart->labels,
        );
        $this->assertSame([1_000_000, 2_111_000, 400_000, 300_000, 50_000], $chart->values);
    }

    public function test_the_region_chart_reads_every_regions_books(): void
    {
        $home = $this->currentRegion();
        $jkt = Region::factory()->create(['kode' => 'JKT']);

        $this->invoiceInRegion($home, 10_000_000);
        $this->invoiceInRegion($jkt, 4_000_000);

        // Pinned to home — the chart must still see Jakarta, because it is
        // only ever shown to viewers who already see all regions.
        $chart = app(SalesReport::class)->regionChart(Period::month(now()->format('Y-m')));

        $this->assertSame([$home->kode, 'JKT'], $chart->labels);
        $this->assertSame([10_000_000, 4_000_000], $chart->values);
    }

    // --- the screens --------------------------------------------------------

    public function test_a_pinned_reader_gets_the_chart_but_never_other_regions(): void
    {
        $home = $this->currentRegion();
        $jkt = Region::factory()->create(['kode' => 'JKT']);

        $this->invoiceInRegion($home, 7_000_000);
        $this->invoiceInRegion($jkt, 3_000_000);

        $finance = User::factory()->finance()->create(['region_id' => $home->id]);

        Livewire::actingAs($finance)
            ->test(Penjualan::class)
            ->set('sampai', now()->toDateString())
            ->set('dari', now()->startOfMonth()->toDateString())
            ->assertOk()
            ->assertSee('Penjualan per pelanggan — terbesar')
            ->assertSee('Rp 7.000.000')
            // Pinned means pinned: the cross-region comparison stays off the
            // page, or this screen quietly undoes the region scope.
            ->assertDontSee('Penjualan per cabang');
    }

    public function test_the_owner_across_all_regions_gets_the_region_comparison(): void
    {
        $home = $this->currentRegion();
        $jkt = Region::factory()->create(['kode' => 'JKT']);

        $this->invoiceInRegion($home, 7_000_000);
        $this->invoiceInRegion($jkt, 3_000_000);

        $owner = User::factory()->owner()->create(['region_id' => null]);
        app(RegionContext::class)->openToAll();

        Livewire::actingAs($owner)
            ->test(Penjualan::class)
            ->set('sampai', now()->toDateString())
            ->set('dari', now()->startOfMonth()->toDateString())
            ->assertOk()
            ->assertSee('Penjualan per cabang')
            ->assertSee('JKT')
            ->assertSee('Rp 3.000.000');
    }

    private function invoiceInRegion(Region $region, int $nilai): void
    {
        app(RegionContext::class)->within($region, function () use ($nilai) {
            $company = Company::factory()->create();
            $order = Order::factory()->create([
                'company_id' => $company->id,
                'warehouse_id' => Warehouse::factory()->create()->id,
            ]);
            OrderLine::factory()->qty(1)->create([
                'order_id' => $order->id,
                'sku' => 'CH-'.fake()->unique()->numerify('####'),
                'line_total_rupiah' => $nilai,
            ]);
            Invoice::factory()->create([
                'order_id' => $order->id,
                'company_id' => $company->id,
                'issued_on' => now()->toDateString(),
            ]);
        });
    }
}
