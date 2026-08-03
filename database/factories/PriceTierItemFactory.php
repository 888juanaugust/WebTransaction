<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PriceTier;
use App\Models\PriceTierItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceTierItem>
 */
class PriceTierItemFactory extends Factory
{
    protected $model = PriceTierItem::class;

    public function definition(): array
    {
        return [
            'price_tier_id' => PriceTier::factory(),
            'kode' => null,
            'min_qty_base' => 1,
            'harga' => null,
            'discount_bps' => null,
        ];
    }
}
