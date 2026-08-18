<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Documents\DocumentNumberGenerator;
use App\Models\BankReconciliation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BankReconciliation> */
class BankReconciliationFactory extends Factory
{
    protected $model = BankReconciliation::class;

    public function definition(): array
    {
        return [
            'nomor' => app(DocumentNumberGenerator::class)->nextBankReconciliationNumber(),
            'tanggal_rekening' => now()->subDay()->toDateString(),
            'saldo_rekening_rupiah' => 0,
            'created_by' => User::factory(),
        ];
    }
}
