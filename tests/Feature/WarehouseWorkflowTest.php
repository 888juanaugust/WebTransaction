<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Filament\Pages\Pengiriman;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The warehouse's job, which CLAUDE.md states as "pick, ship, print surat
 * jalan". Printing did not exist until now, so a role whose entire purpose is
 * getting goods out of the door had no document to send with them.
 *
 * The rule that shapes all of it: warehouse staff cannot see money. The surat
 * jalan carries no prices — it is handed to a driver and then to whoever signs
 * for the delivery, and neither is party to what this customer pays.
 */
class WarehouseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-SJ-1';

    private const UNIT_PRICE = 250_000;

    private Order $order;

    private Warehouse $warehouse;

    private User $warehouseUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create(['nama' => 'Gudang Pusat']);
        $this->warehouseUser = User::factory()->role(Role::Warehouse)->create();

        $company = Company::factory()->creditLimit(900_000_000)->create(['nama' => 'Bengkel Uji']);

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subDay()->toDateString(),
        ]);

        Product::factory()->create([
            'kode' => self::SKU, 'qty_per_ctn' => 12, 'satuan_dasar' => 'PCS',
            'merk' => 'YUHOLI', 'description' => 'Master rem depan',
        ]);
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => self::SKU, 'harga' => self::UNIT_PRICE,
        ]);

        app(StockLedger::class)->record(self::SKU, $this->warehouse->id, 500, MovementReason::Penerimaan);

        $sales = User::factory()->sales()->create();

        $this->order = Order::factory()->create([
            'nomor' => 'SO-SJ-0001',
            'company_id' => $company->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $sales->id,
            'po_pelanggan' => 'PO-9911',
        ]);

        OrderLine::factory()->qty(24)->create(['order_id' => $this->order->id, 'sku' => self::SKU]);

        $machine = app(OrderStateMachine::class);
        $machine->submit($this->order->refresh(), $sales);
        $machine->confirm($this->order->refresh(), $this->approver());

        $this->order->refresh();
    }

    private function suratJalanUrl(?Order $order = null): string
    {
        return route('dokumen.surat-jalan', $order ?? $this->order);
    }

    // --- the document -------------------------------------------------------

    public function test_the_warehouse_can_print_a_surat_jalan(): void
    {
        $response = $this->actingAs($this->warehouseUser)->get($this->suratJalanUrl());

        $response->assertOk()
            ->assertSee('Surat Jalan')
            ->assertSee('SO-SJ-0001')
            ->assertSee('Bengkel Uji')
            ->assertSee('Gudang Pusat')
            ->assertSee('PO-9911')
            ->assertSee(self::SKU)
            // Both the ordered quantity and the base quantity, because the
            // packer counts pieces and the customer ordered in pieces here.
            ->assertSee('24');
    }

    /**
     * The whole reason this document is separate from the faktur.
     *
     * A price on a delivery note is read by a driver and by whoever signs for
     * the goods. Neither is party to what this customer pays, and wholesale
     * pricing is per-customer by definition.
     */
    public function test_the_surat_jalan_shows_no_prices_at_all(): void
    {
        $response = $this->actingAs($this->warehouseUser)->get($this->suratJalanUrl());

        $html = $response->getContent();

        $this->assertStringNotContainsString('Rp', $html, 'no rupiah figure may appear');
        $this->assertStringNotContainsString('250.000', $html);
        $this->assertStringNotContainsString('6.000.000', $html, 'nor the line total');
        $this->assertStringNotContainsString('PPN', $html);
        $this->assertStringNotContainsString('Harga', $html);
    }

    public function test_the_surat_jalan_carries_signature_blocks(): void
    {
        $this->actingAs($this->warehouseUser)
            ->get($this->suratJalanUrl())
            ->assertSee('Disiapkan oleh')
            ->assertSee('Diterima oleh');
    }

    // --- who may print it ---------------------------------------------------

    /**
     * @return list<array{0: Role, 1: bool}>
     */
    public static function printers(): array
    {
        return [
            'warehouse' => [Role::Warehouse, true],
            'owner' => [Role::Owner, true],
            'sales' => [Role::Sales, false],
            'finance' => [Role::Finance, false],
        ];
    }

    #[DataProvider('printers')]
    public function test_only_picking_roles_may_print_a_surat_jalan(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create())
            ->get($this->suratJalanUrl());

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public function test_a_guest_cannot_print_a_surat_jalan(): void
    {
        $this->get($this->suratJalanUrl())->assertRedirect();
    }

    /**
     * Before `confirmed` nothing is reserved, so a delivery note would describe
     * goods the warehouse has not been told to set aside.
     */
    public function test_an_unconfirmed_order_has_no_surat_jalan(): void
    {
        $draft = Order::factory()->create([
            'company_id' => $this->order->company_id,
            'warehouse_id' => $this->warehouse->id,
        ]);
        OrderLine::factory()->qty(5)->create(['order_id' => $draft->id, 'sku' => self::SKU]);

        $this->actingAs($this->warehouseUser)
            ->get($this->suratJalanUrl($draft->refresh()))
            ->assertForbidden();
    }

    // --- the pick list ------------------------------------------------------

    public function test_the_shipping_page_is_warehouse_only(): void
    {
        $this->assertTrue(
            $this->actingAsRole(Role::Warehouse) && Pengiriman::canAccess(),
            'warehouse must reach the shipping page'
        );

        $this->actingAsRole(Role::Owner);
        $this->assertTrue(Pengiriman::canAccess());

        $this->actingAsRole(Role::Sales);
        $this->assertFalse(Pengiriman::canAccess(), 'sales do not pick');

        $this->actingAsRole(Role::Finance);
        $this->assertFalse(Pengiriman::canAccess(), 'finance do not pick');
    }

    private function actingAsRole(Role $role): bool
    {
        $this->actingAs(User::factory()->role($role)->create());

        return true;
    }

    /** The badge is what tells a packer there is work without opening the page. */
    public function test_the_shipping_badge_counts_orders_waiting_to_be_picked(): void
    {
        $this->actingAs($this->warehouseUser);

        $this->assertNull(Pengiriman::getNavigationBadge(), 'nothing is paid yet');

        $this->payFor($this->order);

        $this->assertSame('1', Pengiriman::getNavigationBadge());
    }

    public function test_shipping_moves_the_stock_ledger(): void
    {
        $this->payFor($this->order);

        $before = app(StockLedger::class)->onHandFromLedger(self::SKU, $this->warehouse->id);

        app(OrderStateMachine::class)->ship($this->order->refresh(), $this->warehouseUser);

        $after = app(StockLedger::class)->onHandFromLedger(self::SKU, $this->warehouse->id);

        $this->assertSame($before - 24, $after);
        $this->assertSame(OrderStatus::Shipped, $this->order->refresh()->status);
        $this->assertSame([], app(StockLedger::class)->reconcile());
    }

    /** `paid` is webhook-only, so a test gets there the same way the job does. */
    private function payFor(Order $order): void
    {
        $machine = app(OrderStateMachine::class);
        $machine->awaitPayment($order->refresh(), User::factory()->finance()->create());
        $machine->markPaid($order->refresh(), ['test' => true]);
    }
}
