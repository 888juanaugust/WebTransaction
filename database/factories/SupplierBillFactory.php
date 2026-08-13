<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Documents\DocumentNumberGenerator;
use App\Models\Supplier;
use App\Models\SupplierBill;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierBill> */
class SupplierBillFactory extends Factory
{
    protected $model = SupplierBill::class;

    public function definition(): array
    {
        return [
            'nomor' => app(DocumentNumberGenerator::class)->nextSupplierBillNumber(),
            'supplier_id' => Supplier::factory(),
            'nomor_faktur_supplier' => 'INV/'.fake()->unique()->numerify('#####'),
            'tanggal_faktur' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ];
    }
}
