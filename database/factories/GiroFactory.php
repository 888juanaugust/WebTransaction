<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Giro\GiroDirection;
use App\Models\Company;
use App\Models\Giro;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Giro> */
class GiroFactory extends Factory
{
    protected $model = Giro::class;

    public function definition(): array
    {
        return [
            'nomor' => app(DocumentNumberGenerator::class)->nextGiroNumber(),
            'arah' => GiroDirection::Masuk,
            'company_id' => Company::factory(),
            'bank_penerbit' => 'BCA',
            'nomor_warkat' => fake()->unique()->numerify('AB######'),
            'nilai_rupiah' => 10_000_000,
            'tanggal_terima' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addDays(60)->toDateString(),
            'created_by' => User::factory(),
        ];
    }

    public function masuk(Company $company, int $nilai): static
    {
        return $this->state(fn () => [
            'arah' => GiroDirection::Masuk,
            'company_id' => $company->id,
            'supplier_id' => null,
            'nilai_rupiah' => $nilai,
        ]);
    }

    public function keluar(Supplier $supplier, int $nilai): static
    {
        return $this->state(fn () => [
            'arah' => GiroDirection::Keluar,
            'company_id' => null,
            'supplier_id' => $supplier->id,
            'nilai_rupiah' => $nilai,
        ]);
    }

    public function dueOn(string $date): static
    {
        return $this->state(fn () => ['tanggal_jatuh_tempo' => $date]);
    }
}
