<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalogue\Golongan;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'kode' => strtoupper(fake()->unique()->bothify('??-####')),
            'merk' => fake()->randomElement(['YUHOLI', 'OSBORN', 'ASTRO', 'STAVO', 'STAVIX', 'SERVO', 'BDAX']),
            'kategori' => fake()->randomElement([
                'HYDRAULIC PART', 'SUSPENSION PART', 'ELECTRIC PART', 'BEARING PART',
            ]),
            'tipe_produk' => 'SHOCK ABSORBER',
            'mobil' => 'AVANZA',
            'part_number' => strtoupper(fake()->bothify('#####-#####')),
            'description' => fake()->words(4, true),
            'qty_per_ctn' => 10,
            'satuan_dasar' => 'PCS',
            'aktif' => true,
        ];
    }

    public function soldAsSet(): static
    {
        return $this->state(fn () => ['satuan_dasar' => 'SET']);
    }

    /** Which stream the part came through. Left null by default, as the real catalogue is. */
    public function golongan(Golongan $golongan): static
    {
        return $this->state(fn () => ['golongan' => $golongan->value]);
    }

    public function perCarton(int $qty): static
    {
        return $this->state(fn () => ['qty_per_ctn' => $qty]);
    }
}
