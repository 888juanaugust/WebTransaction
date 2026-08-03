<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'kode' => 'GD'.fake()->unique()->numberBetween(1, 9999),
            'nama' => 'Gudang '.fake()->city(),
            'alamat' => fake()->address(),
            'aktif' => true,
        ];
    }
}
