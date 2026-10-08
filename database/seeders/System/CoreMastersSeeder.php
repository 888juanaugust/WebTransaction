<?php

namespace Database\Seeders\System;

use App\Models\GeneralLedger\Account;
use App\Models\Inventory\ItemCategory;
use App\Models\Inventory\Unit;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\VendorCategory;
use App\Models\Purchasing\VendorType;
use App\Models\Sales\CustomerCategory;
use App\Models\Sales\PriceCategory;
use Illuminate\Database\Seeder;

/**
 * The masters the system cannot run without: one unit, one default category
 * of each kind on the seeded accounts, the in-transit warehouse and one real one.
 */
class CoreMastersSeeder extends Seeder
{
    public function run(): void
    {
        Unit::query()->firstOrCreate(['name' => 'PCS'], ['unit_tax_code' => 'UM.0018']);

        $id = fn (string $no): ?int => Account::query()->where('no', $no)->value('id');
        ItemCategory::query()->firstOrCreate(['name' => 'General', 'parent_id' => null], [
            'is_default' => true,
            'inventory_account_id' => $id('1300'),
            'sales_account_id' => $id('4100'),
            'cogs_account_id' => $id('5100'),
            'sales_return_account_id' => $id('4200'),
            'purchase_return_account_id' => $id('1300'),
        ]);

        CustomerCategory::query()->firstOrCreate(['name' => 'General', 'parent_id' => null], ['is_default' => true]);
        VendorCategory::query()->firstOrCreate(['name' => 'General', 'parent_id' => null], ['is_default' => true]);
        foreach (['Supplier', 'Service provider', 'Freight forwarder', 'Other'] as $name) {
            VendorType::query()->firstOrCreate(['name' => $name]);
        }

        PriceCategory::query()->firstOrCreate(['name' => 'General'], ['is_default' => true, 'notes' => 'The price every customer gets unless given another level.']);

        Warehouse::inTransit();
        Warehouse::query()->firstOrCreate(['name' => 'Main Warehouse'], [
            'is_default' => true,
            'used_all_user' => true,
            'is_active' => true,
        ]);
    }
}
