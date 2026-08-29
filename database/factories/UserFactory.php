<?php

namespace Database\Factories;

use App\Domain\Access\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => Role::Sales,
            'is_active' => true,
        ];
    }

    public function role(Role $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    public function sales(): static
    {
        return $this->role(Role::Sales);
    }

    public function marketing(): static
    {
        return $this->role(Role::Marketing);
    }

    /** Inventori, in the panel's own words — the enum case keeps its old name. */
    public function warehouse(): static
    {
        return $this->role(Role::Warehouse);
    }

    /** A packer: the Gudang role, bound to one warehouse. */
    public function storage(int $warehouseId): static
    {
        return $this->role(Role::Storage)->state(['warehouse_id' => $warehouseId]);
    }

    public function finance(): static
    {
        return $this->role(Role::Finance);
    }

    public function owner(): static
    {
        return $this->role(Role::Owner);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
