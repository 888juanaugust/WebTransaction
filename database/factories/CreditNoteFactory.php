<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\CreditNoteType;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CreditNote> */
class CreditNoteFactory extends Factory
{
    protected $model = CreditNote::class;

    public function definition(): array
    {
        return [
            'nomor' => 'NK-'.now()->format('Ym').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'invoice_id' => Invoice::factory(),
            'company_id' => Company::factory(),
            'jenis' => CreditNoteType::Potongan,
            'tanggal' => now()->toDateString(),
            'alasan' => 'Barang tidak sesuai pesanan',
            'status' => CreditNote::STATUS_DRAFT,
            'created_by' => User::factory(),
        ];
    }
}
