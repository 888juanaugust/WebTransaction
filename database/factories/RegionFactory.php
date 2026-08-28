<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Region>
 */
class RegionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'kode' => strtoupper(fake()->unique()->lexify('???')),
            'nama' => 'Wilayah '.fake()->city(),
            'aktif' => true,
        ];
    }

    /** A region that files under its own NPWP rather than the group's. */
    public function badanSendiri(): static
    {
        return $this->state(fn () => [
            'npwp' => fake()->numerify('##.###.###.#-###.###'),
            'nama_wajib_pajak' => 'PT '.fake()->company(),
            'alamat_pajak' => fake()->address(),
        ]);
    }
}
