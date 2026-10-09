<?php

namespace Tests\Feature\Client;

use App\Client\Access\CentralGroups;
use App\Client\Screens\CentralScreen;
use App\Client\Seeders\CentralGroupSeeder;
use App\Domain\Access\Hak;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Models\Settings\AccessGroup;
use Tests\TestCase;

/** The six roles hold the rights of CLAUDE.md's role table, and the owner's reshaping survives a reseed. */
class RightsMatrixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function rights(string $group, MenuKey|CentralScreen $screen): array
    {
        $matrix = AccessGroup::query()->where('name', $group)->firstOrFail()->load('rights')->rightsMatrix();

        return $matrix[$screen->value] ?? [];
    }

    private function special(string $group): array
    {
        return AccessGroup::query()->where('name', $group)->firstOrFail()->specialRights()->pluck('right')->sort()->values()->all();
    }

    public function test_the_six_roles_exist_with_their_decisive_rights(): void
    {
        $this->assertEqualsCanonicalizing(['Administrator', 'Accounting', 'Finance', 'Sales', 'Purchasing', 'Warehouse', 'Marketing', 'Inventory'], AccessGroup::query()->pluck('name')->all());

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
        $this->assertEqualsCanonicalizing(['view', 'create', 'update', 'delete', 'print'], $this->rights(CentralGroups::INVENTORY, CentralScreen::PriceList));
        $this->assertEqualsCanonicalizing(['view', 'create', 'update', 'delete', 'print'], $this->rights(CentralGroups::INVENTORY, MenuKey::ItemsAndServices));
        $this->assertSame([HakKhusus::SeeCost->value], $this->special(CentralGroups::INVENTORY));

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

        // The owner holds everything.
        $this->assertEqualsCanonicalizing(['view', 'create', 'update', 'delete', 'print'], $this->rights('Administrator', CentralScreen::Teams));
        $this->assertCount(count(HakKhusus::cases()), $this->special('Administrator'));
    }

    public function test_a_group_the_owner_reshaped_survives_a_reseed(): void
    {
        $sales = AccessGroup::query()->where('name', CentralGroups::SALES)->firstOrFail();
        $sales->syncRights([MenuKey::SalesOrders->value => [Hak::View->value], CentralScreen::PriceList->value => [Hak::View->value]]);

        (new CentralGroupSeeder)->run();

        $this->assertSame(['view'], $this->rights(CentralGroups::SALES, MenuKey::SalesOrders));
    }
}
