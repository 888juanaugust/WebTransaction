<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Access\StaffRegistrar;
use App\Domain\Orders\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The packer's seat: one warehouse, one account, one queue.
 *
 * The role exists so that marketing's approval lands the goods on exactly
 * one person's desk. The boundaries that make that true — a warehouse can
 * hold only one active packer, a packer cannot touch another gudang's
 * orders, and the region follows the warehouse — are what break silently if
 * untested, because everything still *renders*.
 */
class GudangRoleTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $gudang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->create(['nama' => 'Gudang Timur']);
    }

    public function test_a_storage_account_requires_a_warehouse_and_inherits_its_region(): void
    {
        $owner = User::factory()->owner()->create();

        $packer = app(StaffRegistrar::class)->create(
            'Pak Gudang', 'packer@example.test', Role::Storage,
            'sandi-yang-panjang', $owner, warehouseId: $this->gudang->id,
        );

        $this->assertSame($this->gudang->id, (int) $packer->warehouse_id);
        $this->assertSame((int) $this->gudang->region_id, (int) $packer->region_id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/dipilihkan gudangnya/');

        app(StaffRegistrar::class)->create(
            'Tanpa Gudang', 'nowhere@example.test', Role::Storage, 'sandi-yang-panjang', $owner,
        );
    }

    public function test_one_warehouse_holds_at_most_one_active_packer(): void
    {
        $owner = User::factory()->owner()->create();
        $registrar = app(StaffRegistrar::class);

        $first = $registrar->create(
            'Packer Satu', 'p1@example.test', Role::Storage,
            'sandi-yang-panjang', $owner, warehouseId: $this->gudang->id,
        );

        try {
            $registrar->create(
                'Packer Dua', 'p2@example.test', Role::Storage,
                'sandi-yang-panjang', $owner, warehouseId: $this->gudang->id,
            );
            $this->fail('Gudang yang sudah dipegang menerima packer kedua.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('satu gudang satu akun', $e->getMessage());
        }

        // Deactivating the holder frees the seat — that is the handover path.
        $registrar->deactivate($first, $owner);

        $second = $registrar->create(
            'Packer Dua', 'p2@example.test', Role::Storage,
            'sandi-yang-panjang', $owner, warehouseId: $this->gudang->id,
        );

        $this->assertSame($this->gudang->id, (int) $second->warehouse_id);
    }

    public function test_the_role_is_pick_and_ship_only(): void
    {
        $role = Role::Storage;

        $this->assertTrue($role->canPickAndShip());

        // The loading bay sees no money and keeps no catalogue.
        $this->assertFalse($role->canSeeCost());
        $this->assertFalse($role->canSeeCreditData());
        $this->assertFalse($role->canCreateOrders());
        $this->assertFalse($role->canApproveOrders());
        $this->assertFalse($role->canConfirmPayment());
        $this->assertFalse($role->canManagePriceList());
        $this->assertFalse($role->canSeeBooks());
        $this->assertFalse($role->canManageStaff());
    }

    public function test_a_packer_cannot_print_another_warehouses_surat_jalan(): void
    {
        $other = Warehouse::factory()->create(['nama' => 'Gudang Barat']);
        $packer = User::factory()->storage($other->id)->create();

        $order = Order::factory()->create([
            'warehouse_id' => $this->gudang->id,
            'status' => OrderStatus::AwaitingPayment,
        ]);

        $this->actingAs($packer, 'web')
            ->get("/dokumen/surat-jalan/{$order->id}")
            ->assertForbidden();

        // Their own warehouse's paperwork prints fine.
        $milikSendiri = Order::factory()->create([
            'warehouse_id' => $other->id,
            'status' => OrderStatus::AwaitingPayment,
        ]);

        $this->actingAs($packer, 'web')
            ->get("/dokumen/surat-jalan/{$milikSendiri->id}")
            ->assertOk();
    }

    public function test_the_shipping_screen_opens_for_the_packer_and_hides_money_screens(): void
    {
        $packer = User::factory()->storage($this->gudang->id)->create();

        // The brand corner names the role and, for a packer, their gudang —
        // the answer to "which account is this open on", always in view.
        $this->actingAs($packer, 'web')->get('/admin/pengiriman')
            ->assertOk()
            ->assertSee(Role::Storage->label())
            ->assertSee($this->gudang->nama);
        $this->actingAs($packer, 'web')->get('/admin/invoices')->assertForbidden();
        $this->actingAs($packer, 'web')->get('/admin/laporan/komisi')->assertForbidden();
    }
}
