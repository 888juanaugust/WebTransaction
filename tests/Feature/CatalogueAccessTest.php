<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Filament\Pages\Pengiriman;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Company;
use App\Models\Order;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Who may change what a SKU is, and who may make a customer disappear.
 *
 * Both answers were "anybody who could open the screen", because neither
 * resource declared one and Filament's default is yes. Every one of these
 * tests fails without the gates.
 */
class CatalogueAccessTest extends TestCase
{
    use RefreshDatabase;

    private function as(Role $role): User
    {
        $user = User::factory()->create(['role' => $role->value, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function sku(string $kode = 'KAT-1', int $qtyPerCtn = 18): Product
    {
        return Product::factory()->create(['kode' => $kode, 'qty_per_ctn' => $qtyPerCtn]);
    }

    // --- who may write the catalogue ---------------------------------------

    public function test_only_the_catalogue_keeper_may_create_edit_or_delete_a_sku(): void
    {
        $product = $this->sku();

        $mayWrite = [Role::Warehouse, Role::Owner];

        foreach (Role::cases() as $role) {
            $this->as($role);
            $expected = in_array($role, $mayWrite, true);

            $this->assertSame($expected, ProductResource::canCreate(), "{$role->value} canCreate");
            $this->assertSame($expected, ProductResource::canEdit($product), "{$role->value} canEdit");
        }
    }

    public function test_a_gudang_clerk_cannot_change_what_a_carton_holds(): void
    {
        $product = $this->sku(qtyPerCtn: 18);

        /*
         * The measured defect, in one line. `qty_per_ctn` is invariant 5's
         * conversion factor: every order that says "2 dus" resolves through
         * it, and the stock ledger is always in base units. A packer moving
         * 18 to 1 moves goods without touching a single stock screen.
         */
        $this->as(Role::Storage);

        $this->assertFalse(ProductResource::canEdit($product));
        $this->assertFalse(ProductResource::canViewAny(), 'the catalogue is not on a packer’s sidebar at all');

        $this->assertSame(18, $product->fresh()->qty_per_ctn);
    }

    public function test_the_delete_button_on_a_sku_is_gated_and_not_merely_declared(): void
    {
        // A SKU with no history, so nothing but the role decides the answer —
        // and Filament's DeleteAction authorises nothing on its own, so the
        // declaration on the resource only bites because the page asks it.
        $product = $this->sku('KAT-BARU');

        $this->as(Role::Storage);

        try {
            Livewire::test(EditProduct::class, ['record' => $product->getKey()])
                ->callAction('delete');
        } catch (\Throwable) {
            // Either refusal is fine; the row must survive.
        }

        $this->assertTrue(
            Product::query()->where('kode', 'KAT-BARU')->exists(),
            'seorang packer menghapus SKU',
        );
    }

    public function test_everybody_who_sells_or_bills_can_still_look_a_part_number_up(): void
    {
        foreach ([Role::Sales, Role::Marketing, Role::Warehouse, Role::Finance, Role::Owner] as $role) {
            $this->as($role);
            $this->assertTrue(ProductResource::canViewAny(), "{$role->value} must still be able to browse");
        }
    }

    public function test_the_catalogue_routes_refuse_and_the_buttons_agree_with_them(): void
    {
        $this->sku('KAT-RUTE');

        /*
         * Two different layers, and both have to say the same thing.
         *
         * Filament's resource *pages* do authorise — CreateRecord and
         * EditRecord abort on canCreate/canEdit — so the route was closed the
         * moment those methods existed. Its *actions* do not, so the browser
         * still showed a sales rep "Buat produk" and an "Ubah" on every row,
         * both leading to a 403. A button that refuses when pressed is a
         * support call, and it teaches people that permission errors are
         * normal.
         */
        $expected = [
            Role::Sales->value => [200, 403],
            Role::Marketing->value => [200, 403],
            Role::Finance->value => [200, 403],
            Role::Warehouse->value => [200, 200],
            Role::Owner->value => [200, 200],
            Role::Storage->value => [403, 403],
        ];

        foreach ($expected as $role => [$list, $write]) {
            $this->as(Role::from($role));

            $this->assertSame($list, $this->get('/admin/products')->getStatusCode(), "{$role} daftar");
            $this->assertSame($write, $this->get('/admin/products/create')->getStatusCode(), "{$role} buat");
            $this->assertSame($write, $this->get('/admin/products/KAT-RUTE/edit')->getStatusCode(), "{$role} ubah");
        }
    }

    // --- a SKU with history is deactivated, not deleted --------------------

    public function test_a_sku_with_a_stock_ledger_cannot_be_deleted_by_anybody(): void
    {
        $product = $this->sku();
        $warehouse = Warehouse::factory()->create();

        $this->as(Role::Warehouse);
        app(StockLedger::class)->record('KAT-1', $warehouse->id, 200, MovementReason::Penerimaan);

        // Not even the catalogue-keeper, and not even the Owner: this is
        // bookkeeping, not permission.
        $this->assertFalse(ProductResource::canDelete($product->fresh()));

        $this->as(Role::Owner);
        $this->assertFalse(ProductResource::canDelete($product->fresh()));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('masih dipakai di buku stok');

        $product->fresh()->delete();
    }

    public function test_the_refusal_names_where_the_sku_is_still_spoken_for(): void
    {
        $product = $this->sku('KAT-2');
        $this->as(Role::Owner);

        // Not stock — a price. The message has to name the right one, because
        // "it is in use somewhere" tells the reader nothing they can act on.
        PriceListItem::factory()->create(['kode' => 'KAT-2']);

        $this->assertSame('daftar harga', $product->firstReference());

        try {
            $product->delete();
            $this->fail('SKU dengan daftar harga seharusnya tidak bisa dihapus.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('KAT-2', $e->getMessage());
            $this->assertStringContainsString('daftar harga', $e->getMessage());
            $this->assertStringContainsString('AKTIF', $e->getMessage(), 'the refusal must name the way out');
        }
    }

    public function test_a_sku_nothing_has_touched_can_still_go(): void
    {
        // A typo made this morning. The guard is about history, not ceremony.
        $product = $this->sku('KAT-TYPO');
        $this->as(Role::Warehouse);

        $this->assertTrue(ProductResource::canDelete($product));

        $product->delete();

        $this->assertFalse(Product::query()->where('kode', 'KAT-TYPO')->exists());
    }

    public function test_deleting_a_sku_would_have_left_its_stock_unaccounted(): void
    {
        $product = $this->sku('KAT-3');
        $warehouse = Warehouse::factory()->create();

        $this->as(Role::Warehouse);
        app(StockLedger::class)->record('KAT-3', $warehouse->id, 200, MovementReason::Penerimaan, valueRupiah: 2_000_000);

        /*
         * Why this is worth a guard rather than a shrug. The movements are
         * not deleted with the product — nothing joins them — and every check
         * that would notice iterates products, so 200 units and Rp 2.000.000
         * simply stop existing as far as the valuation, the unvalued-quantity
         * report and the nightly integrity sweep are concerned.
         */
        $this->assertSame(200, (int) StockMovement::query()->where('sku', 'KAT-3')->sum('qty_signed'));

        try {
            $product->fresh()->delete();
            $this->fail('SKU dengan buku stok seharusnya tidak bisa dihapus.');
        } catch (DomainException) {
            // Expected — and the ledger is still attached to something.
        }

        $this->assertTrue(Product::query()->where('kode', 'KAT-3')->exists());
        $this->assertSame(200, (int) StockMovement::query()->where('sku', 'KAT-3')->sum('qty_signed'));
    }

    // --- the customer record -----------------------------------------------

    public function test_a_sales_rep_can_no_longer_delete_a_customer(): void
    {
        $company = Company::factory()->creditLimit(250_000_000)->create(['nama' => 'PT Uji Hapus']);

        $this->as(Role::Sales);

        $this->assertTrue(CompanyResource::canViewAny(), 'sales still work their customers');
        $this->assertTrue(CompanyResource::canEdit($company));
        $this->assertFalse(CompanyResource::canDelete($company));

        // Asserted by outcome rather than by whether the button is painted:
        // before the gate this call removed the row.
        try {
            Livewire::test(EditCompany::class, ['record' => $company->getKey()])
                ->callAction('delete');
        } catch (\Throwable) {
            // Filament may refuse loudly; either way the row must survive.
        }

        $this->assertTrue(
            Company::query()->where('nama', 'PT Uji Hapus')->exists(),
            'seorang sales menghapus pelanggan',
        );
    }

    public function test_the_owner_keeps_a_way_to_remove_an_account_created_by_mistake(): void
    {
        $company = Company::factory()->create(['nama' => 'PT Salah Ketik']);

        $this->as(Role::Owner);

        $this->assertTrue(CompanyResource::canDelete($company));
    }

    public function test_marketing_and_finance_may_work_a_customer_but_not_remove_one(): void
    {
        $company = Company::factory()->create();

        foreach ([Role::Marketing, Role::Finance] as $role) {
            $this->as($role);
            $this->assertTrue(CompanyResource::canEdit($company), "{$role->value} canEdit");
            $this->assertFalse(CompanyResource::canDelete($company), "{$role->value} canDelete");
        }
    }

    // --- and the screen the packer is left with ----------------------------

    public function test_a_packer_still_reaches_their_own_queue(): void
    {
        $this->as(Role::Storage);

        // The narrowing must not have taken the job with it.
        $this->assertTrue(Pengiriman::canAccess());
    }

    public function test_an_order_cannot_be_deleted_from_the_order_resource_by_anybody(): void
    {
        foreach (Role::cases() as $role) {
            $this->as($role);
            $this->assertFalse(
                OrderResource::canDelete(new Order),
                "{$role->value} canDelete(order)",
            );
        }
    }
}
