<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PriceListVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceListVersion>
 */
class PriceListVersionFactory extends Factory
{
    protected $model = PriceListVersion::class;

    public function definition(): array
    {
        return [
            'effective_from' => now()->subDay()->toDateString(),
            'status' => PriceListVersion::STATUS_DRAFT,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => PriceListVersion::STATUS_PUBLISHED,
            'published_at' => now(),
            'published_by' => User::factory(),
        ]);
    }

    public function effectiveFrom(string $date): static
    {
        return $this->state(fn () => ['effective_from' => $date]);
    }
}
