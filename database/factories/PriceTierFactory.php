<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceTier>
 */
class PriceTierFactory extends Factory
{
    protected $model = PriceTier::class;

    public function definition(): array
    {
        return [
            'kode' => strtoupper(fake()->unique()->bothify('TIER-##')),
            'nama' => 'Distributor',
            'discount_bps' => 0,
            'aktif' => true,
        ];
    }

    public function discount(int $bps): static
    {
        return $this->state(fn () => ['discount_bps' => $bps]);
    }
}
