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

    /**
     * A bill for exactly this amount, with no tax on it.
     *
     * The default leaves the money columns at zero — an unposted bill, since
     * only SupplierBillPoster writes those from the lines. That default used
     * to be harmless because the ledger would accept a payment against a bill
     * owing nothing; it will not any more, and it should not: paying three
     * million against a bill for nothing is not a scenario, it is a fixture
     * that was never asked to make sense.
     *
     * Tax stays at zero deliberately, as on InvoiceFactory: a total whose
     * parts do not add up is an unbalanced journal the moment anything posts
     * it.
     */
    public function totalling(int $rupiah): static
    {
        return $this->state(fn () => [
            'subtotal_rupiah' => $rupiah,
            'dpp_rupiah' => $rupiah,
            'ppn_rupiah' => 0,
            'total_rupiah' => $rupiah,
        ]);
    }
}
