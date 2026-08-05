<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Uom\Unit;
use App\Models\Cart;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class CartItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'sku' => Product::factory(),
            'ordered_unit' => Unit::Pcs,
            'ordered_qty' => 5,
        ];
    }
}
