<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Filament\Pages\Laporan\PelangganPasif;
use App\Filament\Pages\Laporan\Penjualan;
use App\Filament\Pages\Laporan\PerputaranStok;
use App\Filament\Pages\Laporan\UmurPiutang;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The report screens, and who is shown what.
 *
 * Reports are the easiest place to leak margin to somebody who should not see
 * it: the numbers are already aggregated, so a stray column looks harmless
 * until you notice it is cost. Most of this file is about which columns exist
 * for which role.
 */
class ReportScreensTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-LP-1';

    private Warehouse $gudang;

    private User $sales;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('xendit.secret_key', '');

        $this->gudang = Warehouse::factory()->create();
        $this->sales = User::factory()->sales()->create();
        $this->finance = User::factory()->role(Role::Finance)->create();

        Product::factory()->create([
            'kode' => self::SKU, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS',
            'merk' => 'YUHOLI', 'kategori' => 'SUSPENSION PART', 'description' => 'Shock depan',
        ]);

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subYears(2)->toDateString(),
        ]);
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => self::SKU, 'harga' => 100_000,
        ]);

        $this->stockUp(500, 60_000);
    }

    #[DataProvider('reportRoles')]
    public function test_who_may_open_the_sales_report(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(Penjualan::getUrl());

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public static function reportRoles(): array
    {
        return [
            'sales' => [Role::Sales, true],
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
            // Every report here is money, and the warehouse never sees money.
            'gudang' => [Role::Warehouse, false],
        ];
    }

    #[DataProvider('creditRoles')]
    public function test_ageing_follows_credit_data_which_includes_sales(Role $role, bool $allowed): void
    {
        /*
         * Sales are in, on purpose. They already see a customer's remaining
         * credit when placing an order, and they are usually the ones who ring
         * about an overdue invoice. What they are kept away from is cost, and
         * this report has none.
         */
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(UmurPiutang::getUrl());

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public static function creditRoles(): array
    {
        return [
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
            'sales' => [Role::Sales, true],
            'gudang' => [Role::Warehouse, false],
        ];
    }

    #[DataProvider('costRoles')]
    public function test_stock_turnover_is_behind_cost(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(PerputaranStok::getUrl());

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public static function costRoles(): array
    {
        return [
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
            'sales' => [Role::Sales, false],
            /*
             * Inventori joined with the reorganisation: dead stock ranked by
             * the money tied up in it is their report now. Sales stay out —
             * cost next to the prices they negotiate is margin.
             */
            'gudang' => [Role::Warehouse, true],
        ];
    }

    public function test_sales_see_what_they_sold_and_never_what_it_cost(): void
    {
        /*
         * The leak this screen makes easy. Cost plus selling price is margin,
         * and the columns are already aggregated so a stray one looks harmless
         * until somebody notices what it is.
         */
        $this->soldTo('CV Satu', 10);

        Livewire::actingAs($this->sales)
            ->test(Penjualan::class)
            ->set('dari', now()->subMonths(2)->toDateString())
            ->set('sampai', now()->toDateString())
            ->assertOk()
            ->assertSee('CV Satu')
            ->assertSee('Rp 1.000.000')
            ->assertDontSee('HPP')
            ->assertDontSee('Margin')
            ->assertDontSee('Rp 600.000');

        Livewire::actingAs($this->finance)
            ->test(Penjualan::class)
            ->set('dari', now()->subMonths(2)->toDateString())
            ->set('sampai', now()->toDateString())
            ->assertSee('HPP')
            ->assertSee('Rp 600.000');
    }

    public function test_the_grouping_switch_changes_what_is_listed(): void
    {
        $this->soldTo('CV Satu', 10);

        Livewire::actingAs($this->finance)
            ->test(Penjualan::class)
            ->set('dari', now()->subMonths(2)->toDateString())
            ->set('sampai', now()->toDateString())
            ->assertSee('CV Satu')
            ->set('dimensi', 'merk')
            ->assertSee('YUHOLI')
            ->assertDontSee('CV Satu')
            ->set('dimensi', 'kategori')
            ->assertSee('SUSPENSION PART');
    }

    public function test_the_sales_report_opens_on_last_month(): void
    {
        // What somebody sits down with. A part-finished month invites
        // comparing it against whole ones.
        $this->travelTo('2026-08-17 09:00:00');

        Livewire::actingAs($this->finance)
            ->test(Penjualan::class)
            ->assertSet('dari', '2026-07-01')
            ->assertSet('sampai', '2026-07-31');
    }

    public function test_an_empty_period_says_so_rather_than_rendering_nothing(): void
    {
        Livewire::actingAs($this->finance)
            ->test(Penjualan::class)
            ->set('dari', '2020-01-01')
            ->set('sampai', '2020-01-31')
            ->assertOk()
            ->assertSee('Tidak ada data');
    }

    public function test_the_download_gives_a_spreadsheet_the_same_figures(): void
    {
        $this->soldTo('CV Satu', 10);

        $csv = $this->downloadFrom($this->finance);

        $this->assertStringContainsString('CV Satu', $csv);
        $this->assertStringContainsString('1000000', $csv);
    }

    public function test_the_download_respects_the_role_looking_at_it(): void
    {
        // The export is the easiest way round a hidden column if it does not.
        $this->soldTo('CV Satu', 10);

        $csv = $this->downloadFrom($this->sales);

        $this->assertStringContainsString('1000000', $csv);
        $this->assertStringNotContainsString('HPP', $csv);
        $this->assertStringNotContainsString('600000', $csv);
    }

    public function test_lapsed_customers_shows_a_count_on_the_sidebar(): void
    {
        // Worth seeing before opening the page: it is revenue leaving quietly.
        $this->actingAs($this->finance);

        $this->assertNull(PelangganPasif::getNavigationBadge());

        $company = Company::factory()->creditLimit(5_000_000_000)->create([
            'nama' => 'CV Rutin', 'payment_terms_days' => 30, 'status' => Company::STATUS_ACTIVE,
        ]);

        foreach (['2026-01-05', '2026-02-04', '2026-03-06'] as $date) {
            $this->travelTo($date.' 09:00:00');
            $this->settledOrder($company, 5);
        }

        $this->travelTo('2026-08-17 09:00:00');

        $this->assertSame('1', PelangganPasif::getNavigationBadge());
    }

    // --- helpers ------------------------------------------------------------

    /** The CSV the download would hand this user, as text. */
    private function downloadFrom(User $user): string
    {
        $page = Livewire::actingAs($user)
            ->test(Penjualan::class)
            ->set('dari', now()->subMonths(2)->toDateString())
            ->set('sampai', now()->toDateString())
            ->instance();

        $this->actingAs($user);

        ob_start();
        $page->unduh()->sendContent();

        return (string) ob_get_clean();
    }

    private function soldTo(string $nama, int $qty): void
    {
        $company = Company::factory()->creditLimit(5_000_000_000)->create([
            'nama' => $nama, 'payment_terms_days' => 30, 'status' => Company::STATUS_ACTIVE,
        ]);

        $order = $this->settledOrder($company, $qty);

        app(OrderStateMachine::class)->ship(
            $order->refresh(),
            User::factory()->role(Role::Warehouse)->create(),
        );
    }

    private function settledOrder(Company $company, int $qty): Order
    {
        $order = Order::factory()->create([
            'company_id' => $company->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $this->sales->id,
        ]);
        OrderLine::factory()->qty($qty)->create([
            'order_id' => $order->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->submit($order->refresh(), $this->sales);
        $machine->confirm($order->refresh(), $this->sales);
        $machine->awaitPayment($order->refresh(), $this->sales);
        $machine->markPaid($order->refresh(), ['sumber' => 'test']);

        app(PaymentLedger::class)->recordManualPayment(
            company: $company,
            amountRupiah: (int) $order->refresh()->invoice->total_rupiah,
            actor: $this->finance,
            invoice: $order->invoice,
        );

        return $order->refresh();
    }

    private function stockUp(int $qty, int $unitCost): void
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $this->finance->id,
        ]);
        GoodsReceiptLine::factory()->pieces($qty, $unitCost)->create([
            'goods_receipt_id' => $receipt->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);
    }
}
