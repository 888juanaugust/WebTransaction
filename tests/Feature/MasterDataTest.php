<?php

namespace Tests\Feature;

use App\Filament\Resources\Inventory\Items\Pages\CreateItem;
use App\Filament\Resources\Inventory\Warehouses\WarehouseResource;
use App\Filament\Resources\Purchasing\Vendors\Pages\CreateVendor;
use App\Filament\Resources\Sales\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Sales\Customers\Pages\EditCustomer;
use App\Models\Inventory\Item;
use App\Models\Inventory\Unit;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\Vendor;
use App\Models\Sales\Customer;
use App\Models\Sales\PriceCategory;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

/** The big masters through their forms: every tab saves, numbers are drawn from the series. */
class MasterDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAsAdmin();
    }

    public function test_a_customer_is_created_through_every_tab_and_numbered_from_the_series(): void
    {
        $salesman = $this->sampleEmployee();

        Livewire::test(CreateCustomer::class)
            ->fillForm([
                'name' => 'Acme Trading',
                'work_phone' => '021-555',
                'email' => 'acme@example.test',
                'bill_street' => 'Jl. Raya 1',
                'bill_city' => 'Jakarta',
                'contacts' => [['name' => 'Alex Doe', 'position' => 'Owner', 'email' => 'agus@example.test', 'mobile_phone' => '0812']],
                'ship_same_as_bill' => false,
                'ship_street' => 'Jl. Gudang 2',
                'ship_city' => 'Bekasi',
                'addresses' => [['address' => 'Cabang Tangerang, Jl. X']],
                'salesman_id' => $salesman->id,
                'default_sales_disc' => 2.5,
                'default_inc_tax' => true,
                'wp_type' => 'npwp',
                'wp_number' => '01.234.567.8-901.000',
                'wp_name' => 'Acme Trading Ltd',
                'document_code' => 'tax_invoice',
                'openingBalances' => [['document_date' => '2026-09-30', 'amount' => 1500000, 'number' => 'INV-OLD-1', 'description' => 'carried in']],
                'credit_limit_mode' => 'per_customer',
                'credit_limit_amount_enabled' => true,
                'credit_limit_amount' => 25000000,
                'notes' => 'friendly pilot customer',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $customer = Customer::query()->where('name', 'Acme Trading')->firstOrFail();
        $this->assertSame('C-00001', $customer->number);
        $this->assertSame('Jakarta', $customer->bill_city);
        $this->assertSame('Bekasi', $customer->ship_city);
        $this->assertSame('Alex Doe', $customer->contacts()->first()->name);
        $this->assertSame(1, $customer->addresses()->count());
        $this->assertSame($salesman->id, $customer->salesman_id);
        $this->assertSame('2.5000', $customer->default_sales_disc);
        $this->assertSame('npwp', $customer->wp_type->value);
        $this->assertSame(1500000, $customer->openingBalances()->first()->amount);
        $this->assertSame(25000000, $customer->credit_limit_amount);
        $this->assertSame(PriceCategory::query()->where('is_default', true)->value('id'), $customer->price_category_id);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Globex Two', 'manual_number' => true, 'number' => 'CUST-X'])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertDatabaseHas('customers', ['name' => 'Globex Two', 'number' => 'CUST-X']);

        Livewire::test(CreateCustomer::class)->fillForm(['name' => 'Globex Three'])->call('create')->assertHasNoFormErrors();
        $this->assertSame('C-00002', Customer::query()->where('name', 'Globex Three')->value('number'), 'a manual number does not consume the counter');

        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertSchemaStateSet(['name' => 'Acme Trading', 'ship_city' => 'Bekasi'])
            ->fillForm(['name' => 'Acme Trading Group'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Acme Trading Group', $customer->fresh()->name);
        $this->assertDatabaseHas('audit_logs', ['document_type' => 'customer', 'document_id' => $customer->id, 'action' => 'updated']);
    }

    public function test_a_vendor_is_created_with_bank_accounts_and_tax_data(): void
    {
        Livewire::test(CreateVendor::class)
            ->fillForm([
                'name' => 'Contoso Supplies',
                'service_seller' => false,
                'bill_street' => 'Jl. Industri 9',
                'contacts' => [['name' => 'Ibu Sari']],
                'bankAccounts' => [['bank_account' => '1234567890', 'bank_account_name' => 'Contoso Supplies']],
                'default_purchase_disc' => 1,
                'wp_type' => 'npwp',
                'wp_number' => '02.000.000.0-000.000',
                'document_code' => 'domestic',
                'use_bill_number' => true,
                'openingBalances' => [['document_date' => '2026-09-30', 'amount' => 700000]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $vendor = Vendor::query()->where('name', 'Contoso Supplies')->firstOrFail();
        $this->assertSame('V-00001', $vendor->number);
        $this->assertSame('1234567890', $vendor->bankAccounts()->first()->bank_account);
        $this->assertTrue($vendor->use_bill_number);
        $this->assertSame('domestic', $vendor->document_code->value);
        $this->assertSame(700000, $vendor->openingBalances()->first()->amount);
        $this->assertNotNull($vendor->branch_id);
    }

    public function test_an_item_is_created_with_units_prices_and_opening_stock(): void
    {
        $pcs = Unit::query()->where('name', 'PCS')->value('id');
        $ctn = Unit::query()->where('name', 'CTN')->value('id');
        $general = PriceCategory::query()->where('is_default', true)->value('id');
        $wholesale = PriceCategory::query()->create(['name' => 'Wholesale']);
        $warehouse = Warehouse::default();

        Livewire::test(CreateItem::class)
            ->fillForm([
                'name' => 'Shock absorber front',
                'item_type' => 'inventory',
                'unit1_id' => $pcs,
                'sell_price' => 185000,
                'purchase_price' => 120000,
                'min_stock' => 10,
                'units' => [['unit_id' => $ctn, 'ratio' => 12, 'sell_price' => 2100000]],
                'prices' => [
                    ['price_category_id' => $general, 'price' => 185000],
                    ['price_category_id' => $wholesale->id, 'price' => 175000],
                    ['price_category_id' => $wholesale->id, 'unit_id' => $ctn, 'price' => 2040000],
                ],
                'openingStocks' => [['trans_date' => '2026-09-30', 'quantity' => 48, 'unit_cost' => 118000, 'warehouse_id' => $warehouse->id]],
                'length_cm' => 45.5,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = Item::query()->where('name', 'Shock absorber front')->firstOrFail();
        $this->assertSame('ITM-00001', $item->number);
        $this->assertSame(185000, $item->sell_price);
        $this->assertSame('12.000000', $item->units()->first()->ratio);
        $this->assertSame(3, $item->prices()->count());
        $this->assertSame(2040000, $item->prices()->where('unit_id', $ctn)->value('price'));
        $this->assertSame('48.0000', $item->openingStocks()->first()->quantity);
        $this->assertNotNull($item->tax1_id, 'the default VAT code is preselected');
        $this->assertSame('45.50', $item->length_cm);
    }

    public function test_a_warehouse_limited_to_some_users_is_hidden_from_the_others(): void
    {
        $private = Warehouse::query()->create(['name' => 'Branch B store', 'used_all_user' => false]);
        $insider = User::factory()->create();
        $outsider = User::factory()->create();
        $private->users()->attach($insider);
        $group = AccessGroup::query()->where('name', 'Purchasing')->firstOrFail(); // Central's stock keepers (Purchasing) open the Warehouses screen
        $group->users()->attach([$insider->id, $outsider->id]);

        // Central seeds a damaged-goods warehouse per branch, open to all.
        $this->assertEqualsCanonicalizing(['Main Warehouse', 'Branch B store', 'Gudang Rusak PST'], Warehouse::query()->where('is_system', false)->visibleTo($insider)->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Main Warehouse', 'Gudang Rusak PST'], Warehouse::query()->where('is_system', false)->visibleTo($outsider)->pluck('name')->all());

        $this->actingAs($outsider);
        $this->get(WarehouseResource::getUrl('index'))->assertOk()->assertSee('Main Warehouse')->assertDontSee('Branch B store');
        $this->actingAs($insider);
        $this->get(WarehouseResource::getUrl('index'))->assertOk()->assertSee('Branch B store');
    }
}
