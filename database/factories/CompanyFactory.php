<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'kode' => 'C'.fake()->unique()->numberBetween(1000, 999999),
            'nama' => 'Bengkel '.fake()->lastName(),
            'jenis_usaha' => 'bengkel',
            'npwp' => fake()->numerify('##.###.###.#-###.###'),
            'nama_wajib_pajak' => 'PT '.fake()->lastName(),
            'alamat_pajak' => fake()->address(),
            'alamat_kirim' => fake()->address(),
            'kota' => 'Jakarta',
            'telepon' => fake()->numerify('08##########'),
            'credit_limit_rupiah' => 50_000_000,
            'payment_terms_days' => 30,
            'status' => Company::STATUS_ACTIVE,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => Company::STATUS_PENDING]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => Company::STATUS_SUSPENDED]);
    }

    public function creditLimit(int $rupiah): static
    {
        return $this->state(fn () => ['credit_limit_rupiah' => $rupiah]);
    }
}
