<?php

namespace Tests\Feature\Domain;

use App\Domain\Inventory\Units\UnitConverter;
use App\Models\Inventory\Item;
use App\Models\Inventory\Unit;
use Database\Seeders\Defaults\UnitSeeder;
use Database\Seeders\System\CoreMastersSeeder;
use InvalidArgumentException;
use Tests\TestCase;

class UnitConverterTest extends TestCase
{
    public function test_quantities_convert_through_the_item_ratios(): void
    {
        $this->seed([CoreMastersSeeder::class, UnitSeeder::class]);
        $pcs = Unit::query()->where('name', 'PCS')->firstOrFail();
        $ctn = Unit::query()->where('name', 'CTN')->firstOrFail();
        $dozen = Unit::query()->where('name', 'DOZEN')->firstOrFail();

        $item = Item::query()->create(['number' => 'ITM-00001', 'name' => 'Widget', 'unit1_id' => $pcs->id]);
        $item->units()->create(['unit_id' => $ctn->id, 'ratio' => 24, 'sell_price' => 0]);
        $item->units()->create(['unit_id' => $dozen->id, 'ratio' => 12, 'sell_price' => 0]);
        $item->load('units');

        $this->assertSame('1', UnitConverter::ratio($item, $pcs));
        $this->assertSame('48.0000', UnitConverter::toBase($item, 2, $ctn));
        $this->assertSame('6.0000', UnitConverter::toBase($item, '0.5', $dozen));
        $this->assertSame('2.0000', UnitConverter::fromBase($item, 48, $ctn));
        $this->assertSame('7.0000', UnitConverter::toBase($item, 7, $pcs));

        $this->expectException(InvalidArgumentException::class);
        UnitConverter::toBase($item, 1, 9999);
    }
}
