<?php

declare(strict_types=1);

namespace App\Client\Seeders;

use App\Client\Access\CentralGroups;
use App\Client\Screens\CentralScreen;
use App\Domain\Access\Hak;
use App\Models\Settings\AccessGroup;
use Illuminate\Database\Seeder;

/**
 * Who works the price list: Inventory and the administrators in full;
 * Marketing and Sales read it. A group that already holds a right on these
 * screens is left as the owner shaped it.
 */
class PriceListGroupSeeder extends Seeder
{
    public function run(): void
    {
        $all = array_map(fn (Hak $h) => $h->value, Hak::cases());
        $read = [Hak::View->value, Hak::Print->value];
        $screens = [CentralScreen::PriceList->value, CentralScreen::CustomerPrices->value];

        foreach (['Administrator' => $all, CentralGroups::INVENTORY => $all, CentralGroups::MARKETING => $read, CentralGroups::SALES => $read] as $name => $rights) {
            $group = AccessGroup::query()->where('name', $name)->first();
            if ($group === null) {
                continue;
            }
            $matrix = $group->load('rights')->rightsMatrix();
            $missing = array_diff($screens, array_keys($matrix));
            if ($missing === []) {
                continue;
            }
            $group->syncRights($matrix + array_fill_keys(array_values($missing), $rights));
        }
    }
}
