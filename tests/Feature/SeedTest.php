<?php

namespace Tests\Feature;

use App\Client\Access\CentralGroups;
use App\Domain\Inventory\StockQuery;
use App\Models\Inventory\Item;
use App\Models\Inventory\Unit;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\Customer;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Database\Seeders\Defaults\AccessGroupSeeder;
use Tests\TestCase;

/** The three seed layers: System and Defaults for every installation, Demo only on request. */
class SeedTest extends TestCase
{
    public function test_the_default_seed_leaves_no_company_data_behind(): void
    {
        $this->seed();

        $this->assertSame(env('ADMIN_EMAIL') ?: 'admin@example.test', User::query()->value('email'));
        $this->assertNotNull(Unit::query()->where('name', 'PCS')->first());
        $this->assertNotNull(Warehouse::default());
        $this->assertEqualsCanonicalizing([...AccessGroupSeeder::GROUPS, CentralGroups::MARKETING, CentralGroups::INVENTORY], AccessGroup::query()->pluck('name')->all());
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, Item::query()->count());
    }

    public function test_the_demo_company_seeds_once_with_opening_stock(): void
    {
        $this->seed();
        $this->seedDemo();
        $this->seedDemo();

        $this->assertSame(3, Customer::query()->count());
        $this->assertSame(6, Item::query()->count());
        $this->assertSame('C-00001', Customer::query()->where('name', 'Acme Trading')->value('number'));
        $widget = Item::query()->where('name', 'Widget A-100')->firstOrFail();
        $this->assertSame('40.0000', StockQuery::onHand($widget->id, Warehouse::default()->id));
    }
}
