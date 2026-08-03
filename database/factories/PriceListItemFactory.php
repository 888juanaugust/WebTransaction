<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceListItem>
 */
class PriceListItemFactory extends Factory
{
    protected $model = PriceListItem::class;

    public function definition(): array
    {
        return [
            'version_id' => PriceListVersion::factory()->published(),
            'kode' => strtoupper(fake()->unique()->bothify('??-####')),
            'harga' => fake()->numberBetween(50, 5000) * 1000,
            'qty_per_ctn' => 10,
            'aktif' => true,
        ];
    }
}
