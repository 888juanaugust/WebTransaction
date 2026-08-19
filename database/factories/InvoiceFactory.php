<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'nomor' => 'INV-'.fake()->unique()->numerify('########'),
            'order_id' => Order::factory(),
            'company_id' => Company::factory(),
            'subtotal_rupiah' => 1_000_000,
            'dpp_rupiah' => 916_667,
            'ppn_rupiah' => 110_000,
            'total_rupiah' => 1_110_000,
            'issued_on' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_OPEN,
            'kode_transaksi' => '04',
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'issued_on' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
        ]);
    }

    /**
     * An invoice for exactly this total, with no tax on it.
     *
     * The tax columns move with the subtotal rather than keeping the default's
     * 110.000. Left behind they make an invoice whose parts do not add up to
     * its total — harmless while nothing posts it, and an unbalanced journal
     * the moment something does.
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
