<?php

declare(strict_types=1);

namespace App\Client\Seeders;

use App\Client\Access\CentralGroups;
use App\Client\Screens\CentralScreen;
use App\Domain\Access\Hak;
use App\Domain\Access\HakKhusus;
use App\Models\Settings\AccessGroup;
use Illuminate\Database\Seeder;

/**
 * Central's roles on top of the base's groups: Marketing (the Sales rights,
 * plus approving orders and seeing credit data) and Inventory (the Warehouse
 * rights plus cost). Sales never approves. A group the owner already shaped
 * on the Access Groups screen is left alone; Central's own screens are
 * granted to the groups that work them.
 */
class CentralGroupSeeder extends Seeder
{
    public function run(): void
    {
        $sales = CentralGroups::find(CentralGroups::SALES);
        $warehouse = CentralGroups::find(CentralGroups::WAREHOUSE);

        $marketing = AccessGroup::query()->firstOrCreate(['name' => CentralGroups::MARKETING], ['restriction_type' => 'preferences']);
        if (! $marketing->rights()->exists() && $sales !== null) {
            $marketing->syncRights($sales->load('rights')->rightsMatrix() + [
                CentralScreen::OrderApprovals->value => [Hak::View->value, Hak::Update->value],
            ]);
            $marketing->syncSpecialRights([HakKhusus::ApproveTransactions->value, HakKhusus::SeeCreditData->value]);
        }

        $inventory = AccessGroup::query()->firstOrCreate(['name' => CentralGroups::INVENTORY], ['restriction_type' => 'preferences']);
        if (! $inventory->rights()->exists() && $warehouse !== null) {
            $inventory->syncRights($warehouse->load('rights')->rightsMatrix());
            $inventory->syncSpecialRights([HakKhusus::SeeCost->value]);
        }

        if ($sales !== null && $sales->specialRights()->where('right', HakKhusus::ApproveTransactions->value)->exists()) {
            $sales->syncSpecialRights($sales->specialRights()->pluck('right')->reject(HakKhusus::ApproveTransactions->value)->values()->all());
        }

        $administrator = CentralGroups::find('Administrator');
        if ($administrator !== null && ! $administrator->rights()->where('menu_key', CentralScreen::Teams->value)->exists()) {
            $all = array_map(fn (Hak $h) => $h->value, Hak::cases());
            $administrator->syncRights($administrator->load('rights')->rightsMatrix() + array_fill_keys(array_map(fn (CentralScreen $s) => $s->value, CentralScreen::cases()), $all));
        }
    }
}
