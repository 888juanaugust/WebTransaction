<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Supplier> */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'kode' => 'SUP-'.fake()->unique()->numerify('####'),
            'nama' => fake()->company(),
            'nama_kontak' => fake()->name(),
            'telepon' => fake()->numerify('+62 21 ########'),
            'email' => fake()->unique()->safeEmail(),
            'alamat' => fake()->address(),
            'aktif' => true,
        ];
    }
}
