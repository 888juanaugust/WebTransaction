<?php

declare(strict_types=1);

namespace App\Client\Seeders;

use App\Client\Domain\Stock\DamagedGoods;
use App\Models\Company\Branch;
use App\Models\Inventory\Warehouse;
use Illuminate\Database\Seeder;

/** One damaged-goods warehouse per cabang (Gudang Rusak), and the loss account a write-off goes to. */
class ScrapWarehouseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Branch::query()->orderBy('id')->get() as $branch) {
            if (Warehouse::query()->where('scrap_warehouse', true)->where('branch_id', $branch->id)->exists()) {
                continue;
            }
            Warehouse::query()->create([
                'name' => 'Gudang Rusak '.($branch->code ?: $branch->name), 'description' => 'Damaged goods of '.$branch->name.'; never sold from here.',
                'branch_id' => $branch->id, 'scrap_warehouse' => true, 'is_default' => false, 'is_system' => false, 'used_all_user' => true, 'is_active' => true,
            ]);
        }
        DamagedGoods::lossAccount();
    }
}
