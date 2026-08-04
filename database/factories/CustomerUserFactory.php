<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\CustomerUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomerUser>
 */
class CustomerUserFactory extends Factory
{
    protected $model = CustomerUser::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'telepon' => fake()->numerify('08##########'),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
