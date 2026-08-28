<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\TeamAssigner;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\JournalDraft;
use App\Domain\Accounting\Ledger;
use App\Domain\Insight\ChartStats;
use App\Domain\Orders\OrderStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCost;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The numbers behind the charts. A wrong figure in a table gets challenged;
 * a wrong bar just shapes a decision — so each bar's arithmetic is pinned.
 */
class ChartStatsTest extends TestCase
{
    use RefreshDatabase;

    private ChartStats $stats;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stats = app(ChartStats::class);
    }

    private function committedOrder(Company $company, int $total, ?\DateTimeInterface $at = null, ?User $sales = null): Order
    {
        return Order::factory()->status(OrderStatus::Completed)->create([
            'company_id' => $company->id,
            'total_rupiah' => $total,
            'sales_user_id' => $sales?->id,
            'created_at' => $at ?? now(),
        ]);
    }

    public function test_monthly_spend_zero_fills_and_ignores_drafts(): void
    {
        $toko = Company::factory()->create();

        $this->committedOrder($toko, 2_000_000);
        $this->committedOrder($toko, 3_000_000, now()->subMonthsNoOverflow(2)->startOfMonth()->addDays(5));
        Order::factory()->status(OrderStatus::Draft)->create([
            'company_id' => $toko->id, 'total_rupiah' => 9_000_000,
        ]);

        $grafik = $this->stats->monthlySpend($toko, months: 12);

        $this->assertCount(12, $grafik['labels']);
        $this->assertSame(2_000_000, end($grafik['values']));
        $this->assertSame(3_000_000, $grafik['values'][9]);
        // The draft's nine million appears nowhere.
        $this->assertSame(5_000_000, array_sum($grafik['values']));
    }

    public function test_monthly_sales_filters_by_the_seat(): void
    {
        $region = $this->currentRegion();
        $owner = User::factory()->owner()->create();
        $salesA = User::factory()->sales()->create(['region_id' => $region->id]);
        $salesB = User::factory()->sales()->create(['region_id' => $region->id]);
        $marketing = User::factory()->marketing()->create(['region_id' => null]);

        $tokoA = Company::factory()->create();
        $tokoB = Company::factory()->create();
        app(TeamAssigner::class)->assignMarketing($tokoA, $marketing, $owner);

        $this->committedOrder($tokoA, 1_000_000, sales: $salesA);
        $this->committedOrder($tokoB, 5_000_000, sales: $salesB);

        // A sales sees only orders on their own name.
        $this->assertSame(1_000_000, array_sum($this->stats->monthlySales($salesA)['values']));

        // A marketing sees their customers' orders, whoever sold them.
        $this->assertSame(1_000_000, array_sum($this->stats->monthlySales($marketing)['values']));

        // The Owner sees everything.
        $this->assertSame(6_000_000, array_sum($this->stats->monthlySales($owner)['values']));
    }

    public function test_debt_age_buckets_sit_on_the_organisations_lines(): void
    {
        $toko = Company::factory()->create();

        foreach ([
            [30, 1_000_000],   // inside the printed term
            [31, 2_000_000],   // aging
            [150, 3_000_000],  // at the freeze line, still buying
            [151, 4_000_000],  // frozen
        ] as [$umur, $nilai]) {
            Invoice::factory()->totalling($nilai)->create([
                'company_id' => $toko->id,
                'issued_on' => today()->subDays($umur),
                'due_date' => today()->subDays($umur)->addDays(30),
            ]);
        }

        // A paid invoice is not debt, whatever its age.
        Invoice::factory()->totalling(9_000_000)->create([
            'company_id' => $toko->id,
            'status' => Invoice::STATUS_PAID,
            'issued_on' => today()->subDays(200),
            'due_date' => today()->subDays(170),
        ]);

        $grafik = $this->stats->debtAgeBuckets($toko);

        $this->assertSame([1_000_000, 2_000_000, 3_000_000, 4_000_000], $grafik['values']);
    }

    public function test_outstanding_by_customer_is_scoped_to_a_marketings_seats(): void
    {
        $region = $this->currentRegion();
        $owner = User::factory()->owner()->create();
        $marketing = User::factory()->marketing()->create(['region_id' => null]);

        $milik = Company::factory()->create(['nama' => 'Toko Milik']);
        $lain = Company::factory()->create(['nama' => 'Toko Lain']);
        app(TeamAssigner::class)->assignMarketing($milik, $marketing, $owner);

        Invoice::factory()->totalling(4_000_000)->create([
            'company_id' => $milik->id, 'issued_on' => today(), 'due_date' => today()->addDays(30),
        ]);
        Invoice::factory()->totalling(7_000_000)->create([
            'company_id' => $lain->id, 'issued_on' => today(), 'due_date' => today()->addDays(30),
        ]);

        $this->assertSame(['Toko Milik'], $this->stats->outstandingByCustomer($marketing)['labels']);

        $semua = $this->stats->outstandingByCustomer($owner);
        $this->assertSame(['Toko Lain', 'Toko Milik'], $semua['labels']);
        $this->assertSame([7_000_000, 4_000_000], $semua['values']);
    }

    public function test_stock_flow_splits_in_from_out_by_magnitude(): void
    {
        $gudang = Warehouse::factory()->create();
        Product::factory()->create(['kode' => 'SF-1']);

        foreach ([[50, 0], [-20, 0], [30, 3]] as [$qty, $daysAgo]) {
            $movement = new StockMovement;
            $movement->forceFill([
                'sku' => 'SF-1', 'warehouse_id' => $gudang->id, 'qty_signed' => $qty,
                'reason' => 'penerimaan', 'actor_id' => null,
                'created_at' => now()->subDays($daysAgo),
            ])->save();
        }

        $grafik = $this->stats->stockFlow(days: 30);

        $this->assertSame(80, array_sum($grafik['masuk']));
        $this->assertSame(20, array_sum($grafik['keluar']));
        $this->assertSame(50, end($grafik['masuk']));
    }

    public function test_stock_value_groups_by_category_at_moving_average(): void
    {
        Product::factory()->create(['kode' => 'SV-1', 'kategori' => 'HYDRAULIC PART']);
        Product::factory()->create(['kode' => 'SV-2', 'kategori' => 'HYDRAULIC PART']);
        Product::factory()->create(['kode' => 'SV-3', 'kategori' => 'BEARING PART']);

        foreach ([['SV-1', 10, 500_000], ['SV-2', 5, 250_000], ['SV-3', 2, 400_000]] as [$sku, $qty, $nilai]) {
            ProductCost::query()->create(['sku' => $sku, 'qty_base' => $qty, 'value_rupiah' => $nilai]);
        }

        $grafik = $this->stats->stockValueByCategory();

        $this->assertSame(['HYDRAULIC PART', 'BEARING PART'], $grafik['labels']);
        $this->assertSame([750_000, 400_000], $grafik['values']);
    }

    public function test_revenue_vs_expense_reads_the_journal_itself(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $owner = User::factory()->owner()->create();
        $ledger = app(Ledger::class);

        $ledger->postManual(
            JournalDraft::manual('Penjualan tunai')
                ->debit(AccountCode::KAS, 11_000_000)
                ->kredit(AccountCode::PENJUALAN, 11_000_000),
            $owner,
        );
        $ledger->postManual(
            JournalDraft::manual('Beban ekspedisi')
                ->debit(AccountCode::BEBAN_ONGKOS_KIRIM, 1_500_000)
                ->kredit(AccountCode::KAS, 1_500_000),
            $owner,
        );

        $grafik = $this->stats->revenueVsExpense(months: 12);

        $this->assertSame(11_000_000, end($grafik['pendapatan']));
        $this->assertSame(1_500_000, end($grafik['beban']));
        // Nothing else in the year: every other month is a real zero.
        $this->assertSame(11_000_000, array_sum($grafik['pendapatan']));
        $this->assertSame(1_500_000, array_sum($grafik['beban']));
    }
}
