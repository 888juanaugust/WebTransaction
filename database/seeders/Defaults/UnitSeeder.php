<?php

namespace Database\Seeders\Defaults;

use App\Models\Inventory\Unit;
use Illuminate\Database\Seeder;

/** The packing units most trades use beyond the piece, with their tax-office codes. */
class UnitSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['SET', 'UM.0021'], ['CTN', 'UM.0005'], ['DOZEN', 'UM.0007'], ['BOX', 'UM.0003'], ['KG', 'UM.0014']] as [$name, $tax]) {
            Unit::query()->firstOrCreate(['name' => $name], ['unit_tax_code' => $tax]);
        }
    }
}
