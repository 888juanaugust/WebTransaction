<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StoreVisitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sales_user_id' => User::factory()->sales(),
            'company_id' => Company::factory(),
            'latitude' => -6.2 + $this->faker->randomFloat(4, 0, 0.3),
            'longitude' => 106.8 + $this->faker->randomFloat(4, 0, 0.3),
            'foto_path' => null,
            'visited_at' => now(),
        ];
    }
}
