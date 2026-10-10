<?php

namespace Tests\Feature\Client;

use App\Client\Access\CentralGroups;
use App\Client\Screens\CentralScreen;
use App\Client\Seeders\CentralGroupSeeder;
use App\Domain\Access\Hak;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Models\Company\AuditLog;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use RuntimeException;
use Tests\TestCase;

/** The six roles hold the rights of CLAUDE.md's role table, and the owner's reshaping survives a reseed. */
class RightsMatrixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function rights(string $role, MenuKey|CentralScreen $screen): array
    {
        $matrix = CentralGroups::find($role)->load('rights')->rightsMatrix();

        return $matrix[$screen->value] ?? [];
    }

    private function special(string $role): array
    {
        return CentralGroups::find($role)->specialRights()->pluck('right')->sort()->values()->all();
    }

    public function test_the_six_roles_exist_with_their_decisive_rights(): void
    {
        $this->assertEqualsCanonicalizing(['Administrator', 'Accounting', 'Finance', 'Sales', 'Purchasing', 'Warehouse', 'Marketing', 'Portal'], AccessGroup::query()->pluck('name')->all());

        // Sales works its orders and reads prices; never approves, never sees cost.
        $this->assertEqualsCanonicalizing(['view', 'create', 'update', 'print'], $this->rights(CentralGroups::SALES, MenuKey::SalesOrders));
        $this->assertEqualsCanonicalizing(['view', 'print'], $this->rights(CentralGroups::SALES, CentralScreen::PriceList));
        $this->assertSame([], $this->rights(CentralGroups::SALES, CentralScreen::OrderApprovals));
        $this->assertSame([HakKhusus::SeeCreditData->value], $this->special(CentralGroups::SALES));

        // Marketing approves and erases drafts, sees credit data, sets no price.
        $this->assertEqualsCanonicalizing(['view', 'create', 'update', 'delete', 'print'], $this->rights(CentralGroups::MARKETING, MenuKey::SalesOrders));
        $this->assertEqualsCanonicalizing(['view', 'create', 'update', 'print'], $this->rights(CentralGroups::MARKETING, CentralScreen::OrderApprovals));
        $this->assertSame([HakKhusus::ApproveTransactions->value, HakKhusus::SeeCreditData->value], $this->special(CentralGroups::MARKETING));
        $this->assertSame([], $this->rights(CentralGroups::MARKETING, MenuKey::PriceAndDiscountAdjustments));

        // Inventory owns the catalogue, the price list and cost; never credit data.
        $this->assertEqualsCanonicalizing(['view', 'create', 'update', 'delete', 'print'], $this->rights(CentralGroups::PURCHASING, CentralScreen::PriceList));
        $this->assertEqualsCanonicalizing(['view', 'create', 'update', 'delete', 'print'], $this->rights(CentralGroups::PURCHASING, MenuKey::ItemsAndServices));
        $this->assertSame([HakKhusus::ApproveTransactions->value, HakKhusus::SeeCost->value], $this->special(CentralGroups::PURCHASING));
        $this->assertEqualsCanonicalizing(['view', 'create', 'update', 'delete', 'print'], $this->rights(CentralGroups::PURCHASING, MenuKey::PurchaseOrders), 'purchasing buys');
        $this->assertEqualsCanonicalizing(['view', 'create', 'update', 'delete', 'print'], $this->rights(CentralGroups::PURCHASING, MenuKey::Vendors));
        $this->assertSame([], $this->rights(CentralGroups::PURCHASING, MenuKey::PurchaseInvoices), 'the money of a purchase stays with finance');
        $this->assertSame([], $this->rights(CentralGroups::PURCHASING, MenuKey::PurchasePayments));

        // A Warehouse account delivers and sees stock; nothing else.
        $this->assertEqualsCanonicalizing(['view', 'create', 'update', 'print'], $this->rights(CentralGroups::WAREHOUSE, MenuKey::DeliveryOrders));
        $this->assertSame([], $this->rights(CentralGroups::WAREHOUSE, MenuKey::SalesOrders));
        $this->assertSame([], $this->rights(CentralGroups::WAREHOUSE, MenuKey::ItemsAndServices));
        $this->assertSame([], $this->special(CentralGroups::WAREHOUSE));

        // Finance records money and manages credit; approves no order, changes no price.
        $this->assertEqualsCanonicalizing(['view', 'create', 'update', 'delete', 'print'], $this->rights(CentralGroups::FINANCE, MenuKey::SalesReceipts));
        $this->assertEqualsCanonicalizing(['view', 'create', 'update', 'print'], $this->rights(CentralGroups::FINANCE, MenuKey::Customers));
        $this->assertSame([], $this->rights(CentralGroups::FINANCE, MenuKey::SalesReturns) === ['view', 'print'] ? [] : array_diff($this->rights(CentralGroups::FINANCE, MenuKey::SalesReturns), ['view', 'print']));
        $this->assertSame([HakKhusus::ExportData->value, HakKhusus::OverrideCreditLimit->value, HakKhusus::SeeCreditData->value], $this->special(CentralGroups::FINANCE));
        $this->assertEqualsCanonicalizing(['view', 'create', 'update', 'delete', 'print'], $this->rights(CentralGroups::FINANCE, MenuKey::FixedAssets), 'finance keeps the assets');
        $this->assertEqualsCanonicalizing(['view', 'create', 'update', 'delete', 'print'], $this->rights(CentralGroups::FINANCE, MenuKey::MonthEndProcess), 'finance closes the month');
        $this->assertEqualsCanonicalizing(['view', 'print'], $this->rights(CentralGroups::FINANCE, MenuKey::PurchaseOrders));

        // The owner holds everything.
        $this->assertEqualsCanonicalizing(['view', 'create', 'update', 'delete', 'print'], $this->rights(CentralGroups::ADMINISTRATOR, CentralScreen::Teams));
        $this->assertCount(count(HakKhusus::cases()), $this->special(CentralGroups::ADMINISTRATOR));
    }

    public function test_a_group_the_owner_reshaped_survives_a_reseed(): void
    {
        $sales = CentralGroups::find(CentralGroups::SALES);
        $sales->syncRights([MenuKey::SalesOrders->value => [Hak::View->value], CentralScreen::PriceList->value => [Hak::View->value]]);

        (new CentralGroupSeeder)->run();

        $this->assertSame(['view'], $this->rights(CentralGroups::SALES, MenuKey::SalesOrders));
    }

    public function test_the_roles_are_claimed_by_key_so_the_owner_may_rename_a_group(): void
    {
        $finance = CentralGroups::find(CentralGroups::FINANCE);
        $finance->update(['name' => 'Keuangan']);
        $user = User::factory()->create(['is_active' => true]);
        $finance->users()->attach($user);

        $this->assertTrue(CentralGroups::isMember($user, CentralGroups::FINANCE));
        $this->assertSame('Keuangan', CentralGroups::nameOf(CentralGroups::FINANCE));
        $this->assertNull(AccessGroup::query()->where('name', 'Finance')->first());
    }

    public function test_a_role_group_cannot_be_deleted(): void
    {
        $this->expectException(RuntimeException::class);
        CentralGroups::find(CentralGroups::WAREHOUSE)->delete();
    }

    public function test_the_old_inventory_group_merges_into_purchasing(): void
    {
        $old = AccessGroup::query()->create(['name' => CentralGroups::LEGACY_INVENTORY, 'restriction_type' => 'preferences']);
        $old->syncRights([CentralScreen::PriceList->value => [Hak::View->value]]);
        $user = User::factory()->create(['is_active' => true]);
        $old->users()->attach($user);

        (new CentralGroupSeeder)->run();

        $this->assertNull(AccessGroup::query()->where('name', CentralGroups::LEGACY_INVENTORY)->first());
        $this->assertTrue(CentralGroups::isMember($user, CentralGroups::PURCHASING));
    }

    public function test_reshape_groups_reapplies_the_matrix_to_a_shaped_group_and_audits_it(): void
    {
        $this->actingAsAdmin();
        $sales = CentralGroups::find(CentralGroups::SALES);
        $sales->syncRights([MenuKey::SalesOrders->value => [Hak::View->value], CentralScreen::PriceList->value => [Hak::View->value]]);

        $this->artisan('central:reshape-groups', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(['view'], $this->rights(CentralGroups::SALES, MenuKey::SalesOrders), 'a dry run changes nothing');

        $this->artisan('central:reshape-groups')->assertSuccessful();
        $this->assertEqualsCanonicalizing(['view', 'create', 'update', 'print'], $this->rights(CentralGroups::SALES, MenuKey::SalesOrders));
        $this->assertTrue(AuditLog::query()->where('action', 'groups_reshaped')->where('reference', 'Sales')->exists());

        $this->artisan('central:reshape-groups')->assertSuccessful();
        $this->assertSame(1, AuditLog::query()->where('action', 'groups_reshaped')->where('reference', 'Sales')->count(), 'a second run finds nothing to change');
    }
}
